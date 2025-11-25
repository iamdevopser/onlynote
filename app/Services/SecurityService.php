<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;

class SecurityService
{
    /**
     * Check if IP is whitelisted
     */
    public function isIpWhitelisted($ip = null)
    {
        $ip = $ip ?: Request::ip();
        $whitelistedIps = config('security.ip_whitelist', []);
        
        return in_array($ip, $whitelistedIps);
    }

    /**
     * Check if IP is blacklisted
     */
    public function isIpBlacklisted($ip = null)
    {
        $ip = $ip ?: Request::ip();
        $blacklistedIps = config('security.ip_blacklist', []);
        
        return in_array($ip, $blacklistedIps);
    }

    /**
     * Add IP to whitelist
     */
    public function addToWhitelist($ip, $reason = '')
    {
        $whitelistedIps = config('security.ip_whitelist', []);
        
        if (!in_array($ip, $whitelistedIps)) {
            $whitelistedIps[] = $ip;
            
            // Update config or database
            $this->updateIpList('whitelist', $whitelistedIps);
            
            Log::info("IP {$ip} added to whitelist", ['reason' => $reason]);
        }
        
        return true;
    }

    /**
     * Add IP to blacklist
     */
    public function addToBlacklist($ip, $reason = '')
    {
        $blacklistedIps = config('security.ip_blacklist', []);
        
        if (!in_array($ip, $blacklistedIps)) {
            $blacklistedIps[] = $ip;
            
            // Update config or database
            $this->updateIpList('blacklist', $blacklistedIps);
            
            Log::warning("IP {$ip} added to blacklist", ['reason' => $reason]);
        }
        
        return true;
    }

    /**
     * Remove IP from whitelist
     */
    public function removeFromWhitelist($ip)
    {
        $whitelistedIps = config('security.ip_whitelist', []);
        $whitelistedIps = array_diff($whitelistedIps, [$ip]);
        
        $this->updateIpList('whitelist', array_values($whitelistedIps));
        
        Log::info("IP {$ip} removed from whitelist");
        return true;
    }

    /**
     * Remove IP from blacklist
     */
    public function removeFromBlacklist($ip)
    {
        $blacklistedIps = config('security.ip_blacklist', []);
        $blacklistedIps = array_diff($blacklistedIps, [$ip]);
        
        $this->updateIpList('blacklist', array_values($blacklistedIps));
        
        Log::info("IP {$ip} removed from blacklist");
        return true;
    }

    /**
     * Update IP list in database
     */
    private function updateIpList($type, $ips)
    {
        // Store in database for persistence
        DB::table('security_settings')->updateOrInsert(
            ['key' => "ip_{$type}"],
            ['value' => json_encode($ips), 'updated_at' => now()]
        );
        
        // Clear cache
        Cache::forget("security.ip_{$type}");
    }

    /**
     * Check rate limit for action
     */
    public function checkRateLimit($action, $maxAttempts, $decayMinutes = 1)
    {
        $key = $this->getRateLimitKey($action);
        
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            
            Log::warning("Rate limit exceeded for action: {$action}", [
                'ip' => Request::ip(),
                'user_id' => auth()->id(),
                'seconds_remaining' => $seconds
            ]);
            
            return [
                'allowed' => false,
                'seconds_remaining' => $seconds,
                'retry_after' => now()->addSeconds($seconds)
            ];
        }
        
        RateLimiter::hit($key, $decayMinutes * 60);
        
        return [
            'allowed' => true,
            'attempts_remaining' => RateLimiter::remaining($key, $maxAttempts)
        ];
    }

    /**
     * Get rate limit key
     */
    private function getRateLimitKey($action)
    {
        $user = auth()->user();
        $ip = Request::ip();
        
        if ($user) {
            return "rate_limit:{$action}:user:{$user->id}";
        }
        
        return "rate_limit:{$action}:ip:{$ip}";
    }

    /**
     * Clear rate limit for action
     */
    public function clearRateLimit($action)
    {
        $key = $this->getRateLimitKey($action);
        RateLimiter::clear($key);
        
        return true;
    }

    /**
     * Log security event
     */
    public function logSecurityEvent($type, $data = [])
    {
        $logData = array_merge([
            'type' => $type,
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'user_id' => auth()->id(),
            'timestamp' => now(),
            'url' => Request::fullUrl(),
            'method' => Request::method()
        ], $data);
        
        // Log to database
        DB::table('security_logs')->insert($logData);
        
        // Log to file
        Log::channel('security')->info("Security event: {$type}", $logData);
        
        return true;
    }

    /**
     * Get security logs
     */
    public function getSecurityLogs($filters = [], $limit = 100)
    {
        $query = DB::table('security_logs');
        
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        
        if (isset($filters['ip'])) {
            $query->where('ip', $filters['ip']);
        }
        
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        
        if (isset($filters['date_from'])) {
            $query->where('timestamp', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('timestamp', '<=', $filters['date_to']);
        }
        
        return $query->orderBy('timestamp', 'desc')
                    ->limit($limit)
                    ->get();
    }

    /**
     * Check for suspicious activity
     */
    public function checkSuspiciousActivity($userId = null)
    {
        $ip = Request::ip();
        $suspiciousActivities = [];
        
        // Check for multiple failed login attempts
        $failedLogins = $this->getFailedLoginAttempts($ip, $userId);
        if ($failedLogins > 5) {
            $suspiciousActivities[] = [
                'type' => 'multiple_failed_logins',
                'severity' => 'high',
                'description' => "Multiple failed login attempts: {$failedLogins}"
            ];
        }
        
        // Check for unusual access patterns
        $unusualAccess = $this->checkUnusualAccess($ip, $userId);
        if ($unusualAccess) {
            $suspiciousActivities[] = [
                'type' => 'unusual_access_pattern',
                'severity' => 'medium',
                'description' => 'Unusual access pattern detected'
            ];
        }
        
        // Check for rapid requests
        $rapidRequests = $this->checkRapidRequests($ip, $userId);
        if ($rapidRequests) {
            $suspiciousActivities[] = [
                'type' => 'rapid_requests',
                'severity' => 'medium',
                'description' => 'Rapid request pattern detected'
            ];
        }
        
        return $suspiciousActivities;
    }

    /**
     * Get failed login attempts
     */
    private function getFailedLoginAttempts($ip, $userId = null)
    {
        $query = DB::table('security_logs')
            ->where('type', 'failed_login')
            ->where('ip', $ip)
            ->where('timestamp', '>=', now()->subMinutes(15));
        
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        return $query->count();
    }

    /**
     * Check for unusual access patterns
     */
    private function checkUnusualAccess($ip, $userId = null)
    {
        // Check if user is accessing from a new location
        $recentLogins = DB::table('security_logs')
            ->where('type', 'successful_login')
            ->where('user_id', $userId)
            ->where('timestamp', '>=', now()->subDays(30))
            ->pluck('ip')
            ->unique()
            ->toArray();
        
        if (!in_array($ip, $recentLogins)) {
            return true;
        }
        
        return false;
    }

    /**
     * Check for rapid requests
     */
    private function checkRapidRequests($ip, $userId = null)
    {
        $recentRequests = DB::table('security_logs')
            ->where('ip', $ip)
            ->where('timestamp', '>=', now()->subMinutes(1))
            ->count();
        
        return $recentRequests > 100; // More than 100 requests per minute
    }

    /**
     * Block suspicious IP
     */
    public function blockSuspiciousIp($ip, $reason = 'Suspicious activity detected')
    {
        $this->addToBlacklist($ip, $reason);
        
        // Log the blocking action
        $this->logSecurityEvent('ip_blocked', [
            'ip' => $ip,
            'reason' => $reason,
            'blocked_by' => auth()->id()
        ]);
        
        return true;
    }

    /**
     * Get security statistics
     */
    public function getSecurityStats($period = '24h')
    {
        $startTime = match($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay()
        };
        
        $stats = DB::table('security_logs')
            ->where('timestamp', '>=', $startTime)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->get()
            ->keyBy('type');
        
        return [
            'total_events' => $stats->sum('count'),
            'failed_logins' => $stats->get('failed_login')->count ?? 0,
            'successful_logins' => $stats->get('successful_login')->count ?? 0,
            'suspicious_activities' => $stats->get('suspicious_activity')->count ?? 0,
            'ip_blocks' => $stats->get('ip_blocked')->count ?? 0,
            'period' => $period,
            'start_time' => $startTime,
            'end_time' => now()
        ];
    }

    /**
     * Clean old security logs
     */
    public function cleanOldLogs($days = 90)
    {
        $deletedCount = DB::table('security_logs')
            ->where('timestamp', '<', now()->subDays($days))
            ->delete();
        
        Log::info("Cleaned {$deletedCount} old security logs");
        
        return $deletedCount;
    }

    /**
     * Export security logs
     */
    public function exportSecurityLogs($filters = [], $format = 'csv')
    {
        $logs = $this->getSecurityLogs($filters, 10000); // Export up to 10k records
        
        if ($format === 'json') {
            return response()->json($logs);
        }
        
        // CSV export
        $filename = "security_logs_" . now()->format('Y-m-d_H-i-s') . ".csv";
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ];
        
        $callback = function() use ($logs) {
            $file = fopen('php://output', 'w');
            
            // Write headers
            if ($logs->isNotEmpty()) {
                fputcsv($file, array_keys((array) $logs->first()));
            }
            
            // Write data
            foreach ($logs as $log) {
                fputcsv($file, (array) $log);
            }
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }
} 