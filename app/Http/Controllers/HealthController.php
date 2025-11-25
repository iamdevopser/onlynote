<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * Health check endpoint for Docker and AWS
     */
    public function check(): JsonResponse
    {
        $status = [
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'checks' => []
        ];

        // Database check
        try {
            DB::connection()->getPdo();
            $status['checks']['database'] = 'healthy';
        } catch (\Exception $e) {
            $status['status'] = 'unhealthy';
            $status['checks']['database'] = 'unhealthy: ' . $e->getMessage();
        }

        // Cache check
        try {
            Cache::put('health_check', 'ok', 10);
            if (Cache::get('health_check') === 'ok') {
                $status['checks']['cache'] = 'healthy';
            } else {
                $status['status'] = 'unhealthy';
                $status['checks']['cache'] = 'unhealthy: cache not working';
            }
        } catch (\Exception $e) {
            $status['status'] = 'unhealthy';
            $status['checks']['cache'] = 'unhealthy: ' . $e->getMessage();
        }

        // Redis check (if using Redis)
        if (config('cache.default') === 'redis') {
            try {
                Redis::connection()->ping();
                $status['checks']['redis'] = 'healthy';
            } catch (\Exception $e) {
                $status['status'] = 'unhealthy';
                $status['checks']['redis'] = 'unhealthy: ' . $e->getMessage();
            }
        }

        $httpStatus = $status['status'] === 'healthy' ? 200 : 503;
        
        return response()->json($status, $httpStatus);
    }

    /**
     * Simple health check (for nginx direct response)
     */
    public function simple(): \Illuminate\Http\Response
    {
        return response('healthy', 200)
            ->header('Content-Type', 'text/plain');
    }
}

