<?php

namespace App\Services;

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Subscription;
use Stripe\Product;
use Stripe\Price;
use App\Models\User;
use App\Models\StripePayment;
use App\Models\Subscription as UserSubscription;
use App\Models\SubscriptionPlan;
use App\Models\Currency;
use Illuminate\Support\Facades\Log;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create or get Stripe customer
     */
    public function createOrGetCustomer(User $user)
    {
        // Check if user already has a Stripe customer ID
        if ($user->stripe_customer_id) {
            try {
                return Customer::retrieve($user->stripe_customer_id);
            } catch (\Exception $e) {
                Log::error('Failed to retrieve Stripe customer: ' . $e->getMessage());
            }
        }

        // Create new customer
        try {
            $customer = Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => $user->id,
                    'platform' => 'lms'
                ]
            ]);

            // Update user with Stripe customer ID
            $user->update(['stripe_customer_id' => $customer->id]);

            return $customer;
        } catch (\Exception $e) {
            Log::error('Failed to create Stripe customer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create payment intent for one-time payment
     */
    public function createPaymentIntent(User $user, $amount, $currency = 'USD', $metadata = [])
    {
        try {
            $customer = $this->createOrGetCustomer($user);

            $paymentIntent = PaymentIntent::create([
                'amount' => $this->convertToStripeAmount($amount, $currency),
                'currency' => strtolower($currency),
                'customer' => $customer->id,
                'metadata' => array_merge($metadata, [
                    'user_id' => $user->id,
                    'platform' => 'lms'
                ]),
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            return $paymentIntent;
        } catch (\Exception $e) {
            Log::error('Failed to create payment intent: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process successful payment
     */
    public function processPayment($paymentIntentId, $orderId = null)
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            
            $stripePayment = StripePayment::create([
                'user_id' => $paymentIntent->metadata->user_id ?? null,
                'order_id' => $orderId,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_customer_id' => $paymentIntent->customer,
                'payment_method_id' => $paymentIntent->payment_method,
                'amount' => $this->convertFromStripeAmount($paymentIntent->amount, $paymentIntent->currency),
                'currency' => strtoupper($paymentIntent->currency),
                'status' => $paymentIntent->status,
                'payment_method_type' => $paymentIntent->payment_method_types[0] ?? null,
                'payment_method_details' => $paymentIntent->charges->data[0]->payment_method_details ?? null,
                'metadata' => $paymentIntent->metadata->toArray(),
                'paid_at' => $paymentIntent->status === 'succeeded' ? now() : null,
            ]);

            return $stripePayment;
        } catch (\Exception $e) {
            Log::error('Failed to process payment: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create subscription
     */
    public function createSubscription(User $user, SubscriptionPlan $plan, $paymentMethodId = null)
    {
        try {
            if($plan->price > 0) {
                // Stripe Checkout session oluştur
                $customer = $this->createOrGetCustomer($user);
                $stripeProduct = $this->createOrGetProduct($plan);
                $stripePrice = $this->createOrGetPrice($plan, $stripeProduct);
                $session = \Stripe\Checkout\Session::create([
                    'payment_method_types' => ['card'],
                    'mode' => 'subscription',
                    'customer' => $customer->id,
                    'line_items' => [[
                        'price' => $stripePrice->id,
                        'quantity' => 1,
                    ]],
                    'success_url' => url('/dashboard?subscription=success'),
                    'cancel_url' => url('/pricing?subscription=cancel'),
                    'metadata' => [
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'platform' => 'lms'
                    ],
                ]);
                return [
                    'stripe_checkout_url' => $session->url
                ];
            }
            // Ücretsiz planlar için doğrudan abone et
            $subscription = UserSubscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'start_date' => now(),
                'end_date' => now()->addDays($plan->trial_days > 0 ? $plan->trial_days : 30),
                'auto_renew' => false,
            ]);
            return $subscription;
        } catch (\Exception $e) {
            Log::error('Failed to create subscription: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(UserSubscription $subscription, $cancelAtPeriodEnd = true)
    {
        try {
            if ($subscription->stripe_subscription_id) {
                $stripeSubscription = Subscription::retrieve($subscription->stripe_subscription_id);
                
                if ($cancelAtPeriodEnd) {
                    $stripeSubscription->cancel_at_period_end = true;
                } else {
                    $stripeSubscription->cancel();
                }
                
                $stripeSubscription->save();
            }

            $subscription->update([
                'status' => $cancelAtPeriodEnd ? 'active' : 'canceled',
                'canceled_at' => now(),
                'auto_renew' => !$cancelAtPeriodEnd,
            ]);

            return $subscription;
        } catch (\Exception $e) {
            Log::error('Failed to cancel subscription: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create or get Stripe product
     */
    private function createOrGetProduct(SubscriptionPlan $plan)
    {
        if ($plan->stripe_product_id) {
            try {
                return Product::retrieve($plan->stripe_product_id);
            } catch (\Exception $e) {
                Log::warning('Failed to retrieve Stripe product, creating new one: ' . $e->getMessage());
            }
        }

        $product = Product::create([
            'name' => $plan->name,
            'description' => $plan->description,
            'metadata' => [
                'plan_id' => $plan->id,
                'platform' => 'lms'
            ]
        ]);

        $plan->update(['stripe_product_id' => $product->id]);

        return $product;
    }

    /**
     * Create or get Stripe price
     */
    private function createOrGetPrice(SubscriptionPlan $plan, $product)
    {
        if ($plan->stripe_price_id) {
            try {
                return Price::retrieve($plan->stripe_price_id);
            } catch (\Exception $e) {
                Log::warning('Failed to retrieve Stripe price, creating new one: ' . $e->getMessage());
            }
        }

        $priceData = [
            'product' => $product->id,
            'unit_amount' => $this->convertToStripeAmount($plan->price, $plan->currency),
            'currency' => strtolower($plan->currency),
            'recurring' => [
                'interval' => $this->getStripeInterval($plan->billing_cycle),
            ],
            'metadata' => [
                'plan_id' => $plan->id,
                'platform' => 'lms'
            ]
        ];

        $price = Price::create($priceData);

        $plan->update(['stripe_price_id' => $price->id]);

        return $price;
    }

    /**
     * Convert amount to Stripe format (cents)
     */
    private function convertToStripeAmount($amount, $currency)
    {
        $currencyModel = Currency::where('code', strtoupper($currency))->first();
        $decimalPlaces = $currencyModel ? $currencyModel->decimal_places : 2;
        
        return (int) ($amount * pow(10, $decimalPlaces));
    }

    /**
     * Convert amount from Stripe format (cents)
     */
    private function convertFromStripeAmount($amount, $currency)
    {
        $currencyModel = Currency::where('code', strtoupper($currency))->first();
        $decimalPlaces = $currencyModel ? $currencyModel->decimal_places : 2;
        
        return $amount / pow(10, $decimalPlaces);
    }

    /**
     * Get Stripe interval from billing cycle
     */
    private function getStripeInterval($billingCycle)
    {
        switch ($billingCycle) {
            case 'weekly':
                return 'week';
            case 'monthly':
                return 'month';
            case 'yearly':
                return 'year';
            default:
                return 'month';
        }
    }

    /**
     * Handle webhook events
     */
    public function handleWebhook($payload, $signature)
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                config('services.stripe.webhook_secret')
            );

            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $this->handlePaymentSucceeded($event->data->object);
                    break;
                case 'payment_intent.payment_failed':
                    $this->handlePaymentFailed($event->data->object);
                    break;
                case 'customer.subscription.created':
                    $this->handleSubscriptionCreated($event->data->object);
                    break;
                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($event->data->object);
                    break;
                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($event->data->object);
                    break;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Webhook error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function handlePaymentSucceeded($paymentIntent)
    {
        $stripePayment = StripePayment::where('stripe_payment_intent_id', $paymentIntent->id)->first();
        
        if ($stripePayment) {
            $stripePayment->update([
                'status' => 'succeeded',
                'paid_at' => now(),
            ]);
        }
    }

    private function handlePaymentFailed($paymentIntent)
    {
        $stripePayment = StripePayment::where('stripe_payment_intent_id', $paymentIntent->id)->first();
        
        if ($stripePayment) {
            $stripePayment->update(['status' => 'failed']);
        }
    }

    private function handleSubscriptionCreated($subscription)
    {
        $userSubscription = UserSubscription::where('stripe_subscription_id', $subscription->id)->first();
        
        if ($userSubscription) {
            $userSubscription->update([
                'status' => $subscription->status,
                'current_period_start' => date('Y-m-d H:i:s', $subscription->current_period_start),
                'current_period_end' => date('Y-m-d H:i:s', $subscription->current_period_end),
            ]);
        }
    }

    private function handleSubscriptionUpdated($subscription)
    {
        $userSubscription = UserSubscription::where('stripe_subscription_id', $subscription->id)->first();
        
        if ($userSubscription) {
            $userSubscription->update([
                'status' => $subscription->status,
                'current_period_start' => date('Y-m-d H:i:s', $subscription->current_period_start),
                'current_period_end' => date('Y-m-d H:i:s', $subscription->current_period_end),
                'canceled_at' => $subscription->canceled_at ? date('Y-m-d H:i:s', $subscription->canceled_at) : null,
            ]);
        }
    }

    private function handleSubscriptionDeleted($subscription)
    {
        $userSubscription = UserSubscription::where('stripe_subscription_id', $subscription->id)->first();
        
        if ($userSubscription) {
            $userSubscription->update([
                'status' => 'canceled',
                'ended_at' => now(),
                'auto_renew' => false,
            ]);
        }
    }
} 