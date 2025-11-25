<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\Payment;
use App\Models\Order;
use App\Models\User;

class PayPalService
{
    protected $apiUrl;
    protected $clientId;
    protected $clientSecret;
    protected $mode; // sandbox or live
    protected $accessToken;
    protected $accessTokenExpiry;

    public function __construct()
    {
        $this->apiUrl = config('services.paypal.api_url');
        $this->clientId = config('services.paypal.client_id');
        $this->clientSecret = config('services.paypal.client_secret');
        $this->mode = config('services.paypal.mode', 'sandbox');
    }

    /**
     * Get access token
     */
    private function getAccessToken()
    {
        // Check if we have a valid cached token
        if ($this->accessToken && $this->accessTokenExpiry && now()->lt($this->accessTokenExpiry)) {
            return $this->accessToken;
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post($this->apiUrl . '/v1/oauth2/token', [
                    'grant_type' => 'client_credentials'
                ]);

            if (!$response->successful()) {
                throw new \Exception('Failed to get PayPal access token: ' . $response->body());
            }

            $data = $response->json();
            $this->accessToken = $data['access_token'];
            $this->accessTokenExpiry = now()->addSeconds($data['expires_in'] - 300); // 5 minutes buffer

            // Cache the token
            Cache::put('paypal_access_token', $this->accessToken, $data['expires_in'] - 300);

            return $this->accessToken;

        } catch (\Exception $e) {
            Log::error("PayPal access token error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create PayPal order
     */
    public function createOrder($orderData)
    {
        try {
            $accessToken = $this->getAccessToken();

            $payload = [
                'intent' => 'CAPTURE',
                'application_context' => [
                    'return_url' => route('paypal.return'),
                    'cancel_url' => route('paypal.cancel'),
                    'brand_name' => config('app.name'),
                    'landing_page' => 'BILLING',
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING'
                ],
                'purchase_units' => [
                    [
                        'reference_id' => $orderData['order_id'],
                        'description' => $orderData['description'],
                        'amount' => [
                            'currency_code' => $orderData['currency'] ?? 'USD',
                            'value' => number_format($orderData['amount'], 2, '.', ''),
                            'breakdown' => [
                                'item_total' => [
                                    'currency_code' => $orderData['currency'] ?? 'USD',
                                    'value' => number_format($orderData['amount'], 2, '.', '')
                                ]
                            ]
                        ],
                        'items' => $this->formatOrderItems($orderData['items'] ?? [])
                    ]
                ]
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ])->post($this->apiUrl . '/v2/checkout/orders', $payload);

            if (!$response->successful()) {
                throw new \Exception('Failed to create PayPal order: ' . $response->body());
            }

            $paypalOrder = $response->json();

            // Create payment record
            $payment = Payment::create([
                'order_id' => $orderData['order_id'],
                'payment_method' => 'paypal',
                'amount' => $orderData['amount'],
                'currency' => $orderData['currency'] ?? 'USD',
                'status' => 'pending',
                'gateway_order_id' => $paypalOrder['id'],
                'gateway_response' => $paypalOrder,
                'metadata' => [
                    'paypal_order_id' => $paypalOrder['id'],
                    'intent' => $paypalOrder['intent'],
                    'status' => $paypalOrder['status']
                ]
            ]);

            Log::info("PayPal order created successfully", [
                'order_id' => $orderData['order_id'],
                'paypal_order_id' => $paypalOrder['id'],
                'payment_id' => $payment->id
            ]);

            return [
                'success' => true,
                'paypal_order' => $paypalOrder,
                'payment' => $payment,
                'approval_url' => $this->getApprovalUrl($paypalOrder),
                'message' => 'PayPal order created successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to create PayPal order: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create PayPal order: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Format order items for PayPal
     */
    private function formatOrderItems($items)
    {
        $formattedItems = [];

        foreach ($items as $item) {
            $formattedItems[] = [
                'name' => $item['name'],
                'description' => $item['description'] ?? '',
                'quantity' => $item['quantity'] ?? 1,
                'unit_amount' => [
                    'currency_code' => $item['currency'] ?? 'USD',
                    'value' => number_format($item['price'], 2, '.', '')
                ],
                'category' => 'DIGITAL_GOODS'
            ];
        }

        return $formattedItems;
    }

    /**
     * Get approval URL from PayPal order
     */
    private function getApprovalUrl($paypalOrder)
    {
        foreach ($paypalOrder['links'] as $link) {
            if ($link['rel'] === 'approve') {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * Capture PayPal payment
     */
    public function capturePayment($paypalOrderId)
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ])->post($this->apiUrl . "/v2/checkout/orders/{$paypalOrderId}/capture");

            if (!$response->successful()) {
                throw new \Exception('Failed to capture PayPal payment: ' . $response->body());
            }

            $captureData = $response->json();

            // Find payment record
            $payment = Payment::where('gateway_order_id', $paypalOrderId)->first();
            if (!$payment) {
                throw new \Exception('Payment record not found for PayPal order: ' . $paypalOrderId);
            }

            // Update payment status
            $payment->status = $this->mapPayPalStatus($captureData['status']);
            $payment->gateway_response = array_merge($payment->gateway_response ?? [], [
                'capture_data' => $captureData,
                'captured_at' => now()->toISOString()
            ]);

            if (isset($captureData['purchase_units'][0]['payments']['captures'][0])) {
                $capture = $captureData['purchase_units'][0]['payments']['captures'][0];
                $payment->gateway_transaction_id = $capture['id'];
                $payment->gateway_fee = $capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? 0;
            }

            $payment->save();

            // Update order status
            $order = Order::find($payment->order_id);
            if ($order) {
                $order->status = 'paid';
                $order->paid_at = now();
                $order->save();
            }

            Log::info("PayPal payment captured successfully", [
                'paypal_order_id' => $paypalOrderId,
                'payment_id' => $payment->id,
                'status' => $captureData['status']
            ]);

            return [
                'success' => true,
                'capture_data' => $captureData,
                'payment' => $payment,
                'message' => 'PayPal payment captured successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to capture PayPal payment: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to capture PayPal payment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Map PayPal status to internal status
     */
    private function mapPayPalStatus($paypalStatus)
    {
        $statusMap = [
            'COMPLETED' => 'completed',
            'PENDING' => 'pending',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            'DECLINED' => 'declined'
        ];

        return $statusMap[$paypalStatus] ?? 'unknown';
    }

    /**
     * Get PayPal order details
     */
    public function getOrderDetails($paypalOrderId)
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get($this->apiUrl . "/v2/checkout/orders/{$paypalOrderId}");

            if (!$response->successful()) {
                throw new \Exception('Failed to get PayPal order details: ' . $response->body());
            }

            return [
                'success' => true,
                'order_details' => $response->json(),
                'message' => 'PayPal order details retrieved successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to get PayPal order details: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to get PayPal order details: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Refund PayPal payment
     */
    public function refundPayment($captureId, $refundData = [])
    {
        try {
            $accessToken = $this->getAccessToken();

            $payload = [
                'amount' => [
                    'currency_code' => $refundData['currency'] ?? 'USD',
                    'value' => number_format($refundData['amount'], 2, '.', '')
                ],
                'note_to_payer' => $refundData['reason'] ?? 'Refund requested'
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ])->post($this->apiUrl . "/v2/payments/captures/{$captureId}/refund", $payload);

            if (!$response->successful()) {
                throw new \Exception('Failed to refund PayPal payment: ' . $response->body());
            }

            $refundData = $response->json();

            // Find payment record
            $payment = Payment::where('gateway_transaction_id', $captureId)->first();
            if ($payment) {
                $payment->status = 'refunded';
                $payment->gateway_response = array_merge($payment->gateway_response ?? [], [
                    'refund_data' => $refundData,
                    'refunded_at' => now()->toISOString()
                ]);
                $payment->save();
            }

            Log::info("PayPal payment refunded successfully", [
                'capture_id' => $captureId,
                'refund_id' => $refundData['id'] ?? null,
                'payment_id' => $payment->id ?? null
            ]);

            return [
                'success' => true,
                'refund_data' => $refundData,
                'message' => 'PayPal payment refunded successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to refund PayPal payment: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to refund PayPal payment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create subscription
     */
    public function createSubscription($subscriptionData)
    {
        try {
            $accessToken = $this->getAccessToken();

            $payload = [
                'plan_id' => $subscriptionData['plan_id'],
                'start_time' => now()->addMinutes(1)->toISOString(),
                'subscriber' => [
                    'name' => [
                        'given_name' => $subscriptionData['first_name'],
                        'surname' => $subscriptionData['last_name']
                    ],
                    'email_address' => $subscriptionData['email']
                ],
                'application_context' => [
                    'brand_name' => config('app.name'),
                    'locale' => 'en-US',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'SUBSCRIBE_NOW',
                    'payment_method' => [
                        'payer_selected' => 'PAYPAL',
                        'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED'
                    ],
                    'return_url' => route('paypal.subscription.return'),
                    'cancel_url' => route('paypal.subscription.cancel')
                ]
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ])->post($this->apiUrl . '/v1/billing/subscriptions', $payload);

            if (!$response->successful()) {
                throw new \Exception('Failed to create PayPal subscription: ' . $response->body());
            }

            $subscription = $response->json();

            Log::info("PayPal subscription created successfully", [
                'subscription_id' => $subscription['id'],
                'plan_id' => $subscriptionData['plan_id']
            ]);

            return [
                'success' => true,
                'subscription' => $subscription,
                'approval_url' => $this->getSubscriptionApprovalUrl($subscription),
                'message' => 'PayPal subscription created successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to create PayPal subscription: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create PayPal subscription: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get subscription approval URL
     */
    private function getSubscriptionApprovalUrl($subscription)
    {
        foreach ($subscription['links'] as $link) {
            if ($link['rel'] === 'approve') {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * Get subscription details
     */
    public function getSubscriptionDetails($subscriptionId)
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get($this->apiUrl . "/v1/billing/subscriptions/{$subscriptionId}");

            if (!$response->successful()) {
                throw new \Exception('Failed to get PayPal subscription details: ' . $response->body());
            }

            return [
                'success' => true,
                'subscription_details' => $response->json(),
                'message' => 'PayPal subscription details retrieved successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to get PayPal subscription details: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to get PayPal subscription details: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription($subscriptionId, $reason = '')
    {
        try {
            $accessToken = $this->getAccessToken();

            $payload = [
                'reason' => $reason ?: 'Subscription cancelled by user'
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ])->post($this->apiUrl . "/v1/billing/subscriptions/{$subscriptionId}/cancel", $payload);

            if (!$response->successful()) {
                throw new \Exception('Failed to cancel PayPal subscription: ' . $response->body());
            }

            Log::info("PayPal subscription cancelled successfully", [
                'subscription_id' => $subscriptionId,
                'reason' => $reason
            ]);

            return [
                'success' => true,
                'message' => 'PayPal subscription cancelled successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to cancel PayPal subscription: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to cancel PayPal subscription: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Suspend subscription
     */
    public function suspendSubscription($subscriptionId, $reason = '')
    {
        try {
            $accessToken = $this->getAccessToken();

            $payload = [
                'reason' => $reason ?: 'Subscription suspended'
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ])->post($this->apiUrl . "/v1/billing/subscriptions/{$subscriptionId}/suspend", $payload);

            if (!$response->successful()) {
                throw new \Exception('Failed to suspend PayPal subscription: ' . $response->body());
            }

            Log::info("PayPal subscription suspended successfully", [
                'subscription_id' => $subscriptionId,
                'reason' => $reason
            ]);

            return [
                'success' => true,
                'message' => 'PayPal subscription suspended successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to suspend PayPal subscription: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to suspend PayPal subscription: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Reactivate subscription
     */
    public function reactivateSubscription($subscriptionId)
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->post($this->apiUrl . "/v1/billing/subscriptions/{$subscriptionId}/activate");

            if (!$response->successful()) {
                throw new \Exception('Failed to reactivate PayPal subscription: ' . $response->body());
            }

            Log::info("PayPal subscription reactivated successfully", [
                'subscription_id' => $subscriptionId
            ]);

            return [
                'success' => true,
                'message' => 'PayPal subscription reactivated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to reactivate PayPal subscription: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to reactivate PayPal subscription: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get webhook events
     */
    public function getWebhookEvents($startTime = null, $endTime = null)
    {
        try {
            $accessToken = $this->getAccessToken();

            $params = [];
            if ($startTime) {
                $params['start_time'] = $startTime;
            }
            if ($endTime) {
                $params['end_time'] = $endTime;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get($this->apiUrl . '/v1/notifications/webhooks-events', $params);

            if (!$response->successful()) {
                throw new \Exception('Failed to get PayPal webhook events: ' . $response->body());
            }

            return [
                'success' => true,
                'webhook_events' => $response->json(),
                'message' => 'PayPal webhook events retrieved successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to get PayPal webhook events: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to get PayPal webhook events: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature($headers, $body, $webhookId)
    {
        try {
            $accessToken = $this->getAccessToken();

            $payload = [
                'auth_algo' => $headers['PAYPAL-AUTH-ALGO'] ?? '',
                'cert_url' => $headers['PAYPAL-CERT-URL'] ?? '',
                'transmission_id' => $headers['PAYPAL-TRANSMISSION-ID'] ?? '',
                'transmission_sig' => $headers['PAYPAL-TRANSMISSION-SIG'] ?? '',
                'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
                'webhook_id' => $webhookId,
                'webhook_event' => json_decode($body, true)
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ])->post($this->apiUrl . '/v1/notifications/verify-webhook-signature', $payload);

            if (!$response->successful()) {
                throw new \Exception('Failed to verify webhook signature: ' . $response->body());
            }

            $verificationResult = $response->json();

            return [
                'success' => true,
                'verification_result' => $verificationResult,
                'is_valid' => $verificationResult['verification_status'] === 'SUCCESS',
                'message' => 'Webhook signature verification completed'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to verify webhook signature: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to verify webhook signature: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get PayPal account balance
     */
    public function getAccountBalance()
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get($this->apiUrl . '/v1/accounts/balance');

            if (!$response->successful()) {
                throw new \Exception('Failed to get PayPal account balance: ' . $response->body());
            }

            return [
                'success' => true,
                'balance' => $response->json(),
                'message' => 'PayPal account balance retrieved successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to get PayPal account balance: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to get PayPal account balance: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get PayPal statistics
     */
    public function getPayPalStats($startDate = null, $endDate = null)
    {
        try {
            $startDate = $startDate ?? now()->subDays(30);
            $endDate = $endDate ?? now();

            $payments = Payment::where('payment_method', 'paypal')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->get();

            $stats = [
                'total_payments' => $payments->count(),
                'total_amount' => $payments->sum('amount'),
                'successful_payments' => $payments->where('status', 'completed')->count(),
                'failed_payments' => $payments->where('status', 'failed')->count(),
                'pending_payments' => $payments->where('status', 'pending')->count(),
                'refunded_payments' => $payments->where('status', 'refunded')->count(),
                'payments_by_status' => $payments->groupBy('status')
                    ->map(function ($group) {
                        return [
                            'count' => $group->count(),
                            'amount' => $group->sum('amount')
                        ];
                    }),
                'payments_by_date' => $payments->groupBy(function ($payment) {
                    return $payment->created_at->format('Y-m-d');
                })->map(function ($group) {
                    return [
                        'count' => $group->count(),
                        'amount' => $group->sum('amount')
                    ];
                })
            ];

            return [
                'success' => true,
                'stats' => $stats,
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d')
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Failed to get PayPal statistics: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to get PayPal statistics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test PayPal connection
     */
    public function testConnection()
    {
        try {
            $accessToken = $this->getAccessToken();

            // Test API endpoint
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get($this->apiUrl . '/v1/identity/oauth2/userinfo');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'PayPal connection successful',
                    'mode' => $this->mode,
                    'api_url' => $this->apiUrl
                ];
            }

            return [
                'success' => false,
                'message' => 'PayPal connection failed: ' . $response->body()
            ];

        } catch (\Exception $e) {
            Log::error("PayPal connection test failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'PayPal connection test failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies()
    {
        return [
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'CAD' => 'Canadian Dollar',
            'AUD' => 'Australian Dollar',
            'JPY' => 'Japanese Yen',
            'CHF' => 'Swiss Franc',
            'CNY' => 'Chinese Yuan',
            'INR' => 'Indian Rupee',
            'BRL' => 'Brazilian Real'
        ];
    }

    /**
     * Get PayPal mode
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * Check if in sandbox mode
     */
    public function isSandbox()
    {
        return $this->mode === 'sandbox';
    }

    /**
     * Check if in live mode
     */
    public function isLive()
    {
        return $this->mode === 'live';
    }
} 