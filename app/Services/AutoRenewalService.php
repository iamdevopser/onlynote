<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use App\Models\Payment;
use App\Models\Order;
use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class AutoRenewalService
{
    protected $stripeService;
    protected $paypalService;
    protected $notificationService;

    public function __construct()
    {
        // Initialize payment services
        // $this->stripeService = app(StripeService::class);
        // $this->paypalService = app(PayPalService::class);
        // $this->notificationService = app(NotificationService::class);
    }

    /**
     * Process automatic renewals
     */
    public function processAutoRenewals()
    {
        try {
            Log::info("Starting auto-renewal process");

            $renewalsProcessed = 0;
            $renewalsFailed = 0;
            $totalAmount = 0;

            // Get subscriptions due for renewal
            $subscriptions = $this->getSubscriptionsDueForRenewal();

            foreach ($subscriptions as $subscription) {
                try {
                    $result = $this->processSubscriptionRenewal($subscription);
                    
                    if ($result['success']) {
                        $renewalsProcessed++;
                        $totalAmount += $result['amount'];
                        Log::info("Subscription renewed successfully", [
                            'subscription_id' => $subscription->id,
                            'user_id' => $subscription->user_id,
                            'amount' => $result['amount']
                        ]);
                    } else {
                        $renewalsFailed++;
                        Log::warning("Subscription renewal failed", [
                            'subscription_id' => $subscription->id,
                            'user_id' => $subscription->user_id,
                            'error' => $result['message']
                        ]);
                    }

                } catch (\Exception $e) {
                    $renewalsFailed++;
                    Log::error("Error processing subscription renewal", [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info("Auto-renewal process completed", [
                'renewals_processed' => $renewalsProcessed,
                'renewals_failed' => $renewalsFailed,
                'total_amount' => $totalAmount
            ]);

            return [
                'success' => true,
                'renewals_processed' => $renewalsProcessed,
                'renewals_failed' => $renewalsFailed,
                'total_amount' => $totalAmount
            ];

        } catch (\Exception $e) {
            Log::error("Auto-renewal process failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Auto-renewal process failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get subscriptions due for renewal
     */
    protected function getSubscriptionsDueForRenewal()
    {
        $now = now();
        
        return Subscription::with(['user', 'plan', 'course'])
            ->where('status', 'active')
            ->where('auto_renewal', true)
            ->where('next_billing_date', '<=', $now)
            ->where('expires_at', '>', $now)
            ->get();
    }

    /**
     * Process individual subscription renewal
     */
    protected function processSubscriptionRenewal($subscription)
    {
        try {
            DB::beginTransaction();

            // Check if user has valid payment method
            $paymentMethod = $this->getValidPaymentMethod($subscription->user);
            if (!$paymentMethod) {
                return [
                    'success' => false,
                    'message' => 'No valid payment method found'
                ];
            }

            // Process payment
            $paymentResult = $this->processRenewalPayment($subscription, $paymentMethod);
            if (!$paymentResult['success']) {
                return $paymentResult;
            }

            // Update subscription
            $this->updateSubscriptionAfterRenewal($subscription, $paymentResult);

            // Create order record
            $order = $this->createRenewalOrder($subscription, $paymentResult);

            // Send notifications
            $this->sendRenewalNotifications($subscription, $order);

            DB::commit();

            return [
                'success' => true,
                'amount' => $paymentResult['amount'],
                'order_id' => $order->id,
                'payment_id' => $paymentResult['payment_id']
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Subscription renewal failed", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Renewal failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get valid payment method for user
     */
    protected function getValidPaymentMethod($user)
    {
        // Check for Stripe payment method
        if ($user->stripe_customer_id) {
            // This would check Stripe for valid payment methods
            return [
                'type' => 'stripe',
                'customer_id' => $user->stripe_customer_id
            ];
        }

        // Check for PayPal payment method
        if ($user->paypal_email) {
            return [
                'type' => 'paypal',
                'email' => $user->paypal_email
            ];
        }

        return null;
    }

    /**
     * Process renewal payment
     */
    protected function processRenewalPayment($subscription, $paymentMethod)
    {
        try {
            $amount = $subscription->plan->price ?? $subscription->amount;
            $currency = $subscription->plan->currency ?? 'USD';

            switch ($paymentMethod['type']) {
                case 'stripe':
                    return $this->processStripeRenewal($subscription, $amount, $currency);
                    
                case 'paypal':
                    return $this->processPayPalRenewal($subscription, $amount, $currency);
                    
                default:
                    return [
                        'success' => false,
                        'message' => 'Unsupported payment method'
                    ];
            }

        } catch (\Exception $e) {
            Log::error("Payment processing failed", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Payment processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process Stripe renewal
     */
    protected function processStripeRenewal($subscription, $amount, $currency)
    {
        // This would integrate with Stripe API
        // For now, simulate successful payment
        
        $payment = Payment::create([
            'user_id' => $subscription->user_id,
            'amount' => $amount,
            'currency' => $currency,
            'payment_type' => 'stripe',
            'status' => 'completed',
            'gateway_transaction_id' => 'STRIPE_RENEWAL_' . uniqid(),
            'metadata' => [
                'subscription_id' => $subscription->id,
                'renewal_type' => 'auto',
                'processed_at' => now()->toISOString()
            ]
        ]);

        return [
            'success' => true,
            'amount' => $amount,
            'currency' => $currency,
            'payment_id' => $payment->id,
            'transaction_id' => $payment->gateway_transaction_id
        ];
    }

    /**
     * Process PayPal renewal
     */
    protected function processPayPalRenewal($subscription, $amount, $currency)
    {
        // This would integrate with PayPal API
        // For now, simulate successful payment
        
        $payment = Payment::create([
            'user_id' => $subscription->user_id,
            'amount' => $amount,
            'currency' => $currency,
            'payment_type' => 'paypal',
            'status' => 'completed',
            'gateway_transaction_id' => 'PAYPAL_RENEWAL_' . uniqid(),
            'metadata' => [
                'subscription_id' => $subscription->id,
                'renewal_type' => 'auto',
                'processed_at' => now()->toISOString()
            ]
        ]);

        return [
            'success' => true,
            'amount' => $amount,
            'currency' => $currency,
            'payment_id' => $payment->id,
            'transaction_id' => $payment->gateway_transaction_id
        ];
    }

    /**
     * Update subscription after successful renewal
     */
    protected function updateSubscriptionAfterRenewal($subscription, $paymentResult)
    {
        $plan = $subscription->plan;
        $billingCycle = $plan->billing_cycle ?? 'monthly';
        
        // Calculate next billing date
        $nextBillingDate = $this->calculateNextBillingDate($subscription->next_billing_date, $billingCycle);
        
        // Calculate new expiry date
        $newExpiryDate = $this->calculateNewExpiryDate($subscription->expires_at, $billingCycle);

        $subscription->update([
            'last_billing_date' => now(),
            'next_billing_date' => $nextBillingDate,
            'expires_at' => $newExpiryDate,
            'renewal_count' => $subscription->renewal_count + 1,
            'last_payment_amount' => $paymentResult['amount'],
            'last_payment_date' => now(),
            'status' => 'active'
        ]);
    }

    /**
     * Calculate next billing date
     */
    protected function calculateNextBillingDate($currentBillingDate, $billingCycle)
    {
        return match($billingCycle) {
            'weekly' => $currentBillingDate->addWeek(),
            'biweekly' => $currentBillingDate->addWeeks(2),
            'monthly' => $currentBillingDate->addMonth(),
            'quarterly' => $currentBillingDate->addMonths(3),
            'biannual' => $currentBillingDate->addMonths(6),
            'annual' => $currentBillingDate->addYear(),
            default => $currentBillingDate->addMonth()
        };
    }

    /**
     * Calculate new expiry date
     */
    protected function calculateNewExpiryDate($currentExpiryDate, $billingCycle)
    {
        return match($billingCycle) {
            'weekly' => $currentExpiryDate->addWeek(),
            'biweekly' => $currentExpiryDate->addWeeks(2),
            'monthly' => $currentExpiryDate->addMonth(),
            'quarterly' => $currentExpiryDate->addMonths(3),
            'biannual' => $currentExpiryDate->addMonths(6),
            'annual' => $currentExpiryDate->addYear(),
            default => $currentExpiryDate->addMonth()
        };
    }

    /**
     * Create renewal order
     */
    protected function createRenewalOrder($subscription, $paymentResult)
    {
        return Order::create([
            'user_id' => $subscription->user_id,
            'course_id' => $subscription->course_id,
            'instructor_id' => $subscription->course->instructor_id,
            'course_title' => $subscription->course->title,
            'price' => $paymentResult['amount'],
            'payment_id' => $paymentResult['payment_id'],
            'order_type' => 'renewal',
            'subscription_id' => $subscription->id,
            'status' => 'completed',
            'metadata' => [
                'renewal_type' => 'auto',
                'billing_cycle' => $subscription->plan->billing_cycle ?? 'monthly',
                'renewal_number' => $subscription->renewal_count
            ]
        ]);
    }

    /**
     * Send renewal notifications
     */
    protected function sendRenewalNotifications($subscription, $order)
    {
        try {
            // Send email notification to user
            $this->sendUserRenewalNotification($subscription, $order);
            
            // Send notification to instructor
            $this->sendInstructorRenewalNotification($subscription, $order);
            
            // Send system notification
            $this->sendSystemRenewalNotification($subscription, $order);

        } catch (\Exception $e) {
            Log::error("Failed to send renewal notifications", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send user renewal notification
     */
    protected function sendUserRenewalNotification($subscription, $order)
    {
        // This would integrate with email/notification system
        Log::info("User renewal notification sent", [
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'amount' => $order->price
        ]);
    }

    /**
     * Send instructor renewal notification
     */
    protected function sendInstructorRenewalNotification($subscription, $order)
    {
        // This would integrate with email/notification system
        Log::info("Instructor renewal notification sent", [
            'instructor_id' => $subscription->course->instructor_id,
            'subscription_id' => $subscription->id,
            'amount' => $order->price
        ]);
    }

    /**
     * Send system renewal notification
     */
    protected function sendSystemRenewalNotification($subscription, $order)
    {
        // This would integrate with system notification system
        Log::info("System renewal notification sent", [
            'subscription_id' => $subscription->id,
            'order_id' => $order->id
        ]);
    }

    /**
     * Handle failed renewals
     */
    public function handleFailedRenewals()
    {
        try {
            $failedRenewals = $this->getFailedRenewals();

            foreach ($failedRenewals as $subscription) {
                $this->processFailedRenewal($subscription);
            }

            return [
                'success' => true,
                'failed_renewals_processed' => $failedRenewals->count()
            ];

        } catch (\Exception $e) {
            Log::error("Failed to handle failed renewals: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to handle failed renewals: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get failed renewals
     */
    protected function getFailedRenewals()
    {
        $gracePeriod = now()->subDays(3); // 3 days grace period
        
        return Subscription::with(['user', 'plan', 'course'])
            ->where('status', 'active')
            ->where('auto_renewal', true)
            ->where('next_billing_date', '<=', $gracePeriod)
            ->where('last_payment_date', '<', $gracePeriod)
            ->get();
    }

    /**
     * Process failed renewal
     */
    protected function processFailedRenewal($subscription)
    {
        try {
            // Update subscription status
            $subscription->update([
                'status' => 'payment_failed',
                'auto_renewal' => false,
                'last_failure_date' => now(),
                'failure_count' => ($subscription->failure_count ?? 0) + 1
            ]);

            // Send failure notification
            $this->sendRenewalFailureNotification($subscription);

            // Attempt to retry payment if within retry limit
            if (($subscription->failure_count ?? 0) < 3) {
                $this->scheduleRetryPayment($subscription);
            }

        } catch (\Exception $e) {
            Log::error("Failed to process failed renewal", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send renewal failure notification
     */
    protected function sendRenewalFailureNotification($subscription)
    {
        // This would integrate with notification system
        Log::info("Renewal failure notification sent", [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id
        ]);
    }

    /**
     * Schedule retry payment
     */
    protected function scheduleRetryPayment($subscription)
    {
        // Schedule retry for 24 hours later
        $retryDate = now()->addDay();
        
        // This would integrate with job queue system
        Log::info("Retry payment scheduled", [
            'subscription_id' => $subscription->id,
            'retry_date' => $retryDate
        ]);
    }

    /**
     * Get renewal statistics
     */
    public function getRenewalStats($period = 'month')
    {
        $startDate = $this->getStartDate($period);
        
        $stats = [
            'total_renewals' => Subscription::where('last_billing_date', '>=', $startDate)
                ->where('renewal_count', '>', 0)
                ->count(),
            'successful_renewals' => Subscription::where('last_billing_date', '>=', $startDate)
                ->where('status', 'active')
                ->where('renewal_count', '>', 0)
                ->count(),
            'failed_renewals' => Subscription::where('last_failure_date', '>=', $startDate)
                ->where('status', 'payment_failed')
                ->count(),
            'total_revenue' => Order::where('created_at', '>=', $startDate)
                ->where('order_type', 'renewal')
                ->where('status', 'completed')
                ->sum('price'),
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => now()
        ];

        return $stats;
    }

    /**
     * Get start date based on period
     */
    protected function getStartDate($period)
    {
        return match($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth()
        };
    }
} 