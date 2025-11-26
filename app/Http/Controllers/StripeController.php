<?php

namespace App\Http\Controllers;

use App\Services\StripeService;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeController extends Controller
{
    protected $stripeService;

    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    /**
     * Create payment intent for course purchase
     */
    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'order_id' => 'nullable|exists:orders,id'
        ]);

        try {
            $user = auth()->user();
            $metadata = [
                'order_id' => $request->order_id,
                'type' => 'course_purchase'
            ];

            $paymentIntent = $this->stripeService->createPaymentIntent(
                $user,
                $request->amount,
                $request->currency,
                $metadata
            );

            return response()->json([
                'success' => true,
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id
            ]);
        } catch (\Exception $e) {
            Log::error('Payment intent creation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment intent'
            ], 500);
        }
    }

    /**
     * Create subscription
     */
    public function createSubscription(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'payment_method_id' => 'nullable|string'
        ]);

        try {
            $user = auth()->user();
            $plan = SubscriptionPlan::findOrFail($request->plan_id);

            // Check if user already has an active subscription
            $existingSubscription = Subscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if ($existingSubscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have an active subscription'
                ], 400);
            }

            $subscription = $this->stripeService->createSubscription(
                $user,
                $plan,
                $request->payment_method_id
            );

            // Stripe Checkout url dönerse onu response'a ekle
            if(is_array($subscription) && isset($subscription['stripe_checkout_url'])) {
                return response()->json([
                    'success' => true,
                    'subscription' => $subscription,
                    'message' => 'Stripe checkout yönlendirmesi'
                ]);
            }

            return response()->json([
                'success' => true,
                'subscription' => $subscription,
                'message' => 'Subscription created successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription creation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create subscription'
            ], 500);
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(Request $request)
    {
        $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'cancel_at_period_end' => 'boolean'
        ]);

        try {
            $user = auth()->user();
            $subscription = Subscription::where('id', $request->subscription_id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $cancelAtPeriodEnd = $request->get('cancel_at_period_end', true);
            
            $this->stripeService->cancelSubscription($subscription, $cancelAtPeriodEnd);

            return response()->json([
                'success' => true,
                'message' => $cancelAtPeriodEnd 
                    ? 'Subscription will be canceled at the end of the current period'
                    : 'Subscription canceled immediately'
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription cancellation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription'
            ], 500);
        }
    }

    /**
     * Get user's subscription
     */
    public function getSubscription()
    {
        try {
            $user = auth()->user();
            $subscription = Subscription::where('user_id', $user->id)
                ->with('subscriptionPlan')
                ->latest()
                ->first();

            return response()->json([
                'success' => true,
                'subscription' => $subscription
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get subscription: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get subscription'
            ], 500);
        }
    }

    /**
     * Get available subscription plans
     */
    public function getSubscriptionPlans()
    {
        try {
            $plans = SubscriptionPlan::active()
                ->orderBy('price')
                ->get();

            return response()->json([
                'success' => true,
                'plans' => $plans
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get subscription plans: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get subscription plans'
            ], 500);
        }
    }

    /**
     * Handle Stripe webhooks
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        try {
            $this->stripeService->handleWebhook($payload, $signature);
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Webhook handling failed: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Confirm payment
     */
    public function confirmPayment(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'required|string',
            'order_id' => 'nullable|exists:orders,id'
        ]);

        try {
            $stripePayment = $this->stripeService->processPayment(
                $request->payment_intent_id,
                $request->order_id
            );

            // Update order status if order_id is provided
            if ($request->order_id) {
                $order = Order::find($request->order_id);
                if ($order && $stripePayment->isSuccessful()) {
                    $order->update(['status' => 'completed']);
                }
            }

            return response()->json([
                'success' => true,
                'payment' => $stripePayment,
                'message' => 'Payment processed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Payment confirmation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm payment'
            ], 500);
        }
    }

    /**
     * Get payment methods for user
     */
    public function getPaymentMethods()
    {
        try {
            $user = auth()->user();
            
            if (!$user->stripe_customer_id) {
                return response()->json([
                    'success' => true,
                    'payment_methods' => []
                ]);
            }

            $customer = \Stripe\Customer::retrieve($user->stripe_customer_id);
            $paymentMethods = \Stripe\PaymentMethod::all([
                'customer' => $customer->id,
                'type' => 'card'
            ]);

            return response()->json([
                'success' => true,
                'payment_methods' => $paymentMethods->data
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get payment methods: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get payment methods'
            ], 500);
        }
    }

    /**
     * Add payment method
     */
    public function addPaymentMethod(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|string'
        ]);

        try {
            $user = auth()->user();
            $customer = $this->stripeService->createOrGetCustomer($user);

            $paymentMethod = \Stripe\PaymentMethod::retrieve($request->payment_method_id);
            $paymentMethod->attach(['customer' => $customer->id]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method added successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to add payment method: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to add payment method'
            ], 500);
        }
    }

    /**
     * Remove payment method
     */
    public function removePaymentMethod(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|string'
        ]);

        try {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($request->payment_method_id);
            $paymentMethod->detach();

            return response()->json([
                'success' => true,
                'message' => 'Payment method removed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove payment method: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove payment method'
            ], 500);
        }
    }
} 