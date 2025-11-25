<?php

namespace App\Providers;

use App\Models\Stripe;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;

class StripeServiceProvider extends ServiceProvider
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
                // Fetch Stripe settings from the database
                $stripeConfig = Stripe::first();
                if ($stripeConfig) {
                    Config::set('stripe.stripe_pk', $stripeConfig->publish_key);
                    Config::set('stripe.stripe_sk', $stripeConfig->secret_key);
                }
            }
        } catch (\Exception $e) {
            // Database connection failed, use default values
            // You can set default Stripe credentials here if needed
        }
    }
}
