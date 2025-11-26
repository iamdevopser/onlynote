<?php

namespace App\Providers;

use App\Models\Google;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class GoogleServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        try {
            // Check if database connection is available
            if (DB::connection()->getPdo()) {
                // Fetch Google settings from the database
                $googleConfig = Google::first();
                if ($googleConfig) {
                    Config::set('services.google.client_id', $googleConfig->client_id);
                    Config::set('services.google.client_secret', $googleConfig->secret_key);
                }
            }
        } catch (\Exception $e) {
            // Database connection failed, use default values
            // You can set default Google OAuth credentials here if needed
        }
    }
}
