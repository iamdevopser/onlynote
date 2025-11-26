<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

class GoogleAnalyticsService
{
    protected $measurementId;
    protected $apiSecret;
    protected $endpoint = 'https://www.google-analytics.com/mp/collect';
    protected $debugEndpoint = 'https://www.google-analytics.com/debug/mp/collect';
    
    public function __construct()
    {
        $this->measurementId = config('services.google_analytics.measurement_id');
        $this->apiSecret = config('services.google_analytics.api_secret');
    }

    /**
     * Track page view
     */
    public function trackPageView($pageTitle, $pagePath, $userId = null, $options = [])
    {
        $event = [
            'name' => 'page_view',
            'params' => array_merge([
                'page_title' => $pageTitle,
                'page_location' => url($pagePath),
                'page_path' => $pagePath
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track course enrollment
     */
    public function trackCourseEnrollment($courseId, $courseTitle, $userId, $options = [])
    {
        $event = [
            'name' => 'course_enrollment',
            'params' => array_merge([
                'course_id' => $courseId,
                'course_title' => $courseTitle,
                'value' => $options['price'] ?? 0,
                'currency' => $options['currency'] ?? 'TRY',
                'items' => [
                    [
                        'item_id' => $courseId,
                        'item_name' => $courseTitle,
                        'item_category' => $options['category'] ?? 'Course',
                        'price' => $options['price'] ?? 0,
                        'quantity' => 1
                    ]
                ]
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track course completion
     */
    public function trackCourseCompletion($courseId, $courseTitle, $userId, $options = [])
    {
        $event = [
            'name' => 'course_completion',
            'params' => array_merge([
                'course_id' => $courseId,
                'course_title' => $courseTitle,
                'completion_time' => $options['completion_time'] ?? 0,
                'final_score' => $options['final_score'] ?? 0,
                'certificate_earned' => $options['certificate_earned'] ?? false
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track quiz attempt
     */
    public function trackQuizAttempt($quizId, $quizTitle, $userId, $score, $options = [])
    {
        $event = [
            'name' => 'quiz_attempt',
            'params' => array_merge([
                'quiz_id' => $quizId,
                'quiz_title' => $quizTitle,
                'score' => $score,
                'passed' => $options['passed'] ?? ($score >= 70),
                'attempt_number' => $options['attempt_number'] ?? 1,
                'time_taken' => $options['time_taken'] ?? 0
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track payment
     */
    public function trackPayment($orderId, $amount, $currency, $userId, $options = [])
    {
        $event = [
            'name' => 'purchase',
            'params' => array_merge([
                'transaction_id' => $orderId,
                'value' => $amount,
                'currency' => $currency,
                'payment_method' => $options['payment_method'] ?? 'unknown',
                'items' => $options['items'] ?? []
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track user registration
     */
    public function trackUserRegistration($userId, $userData, $options = [])
    {
        $event = [
            'name' => 'user_registration',
            'params' => array_merge([
                'user_id' => $userId,
                'registration_method' => $options['registration_method'] ?? 'email',
                'user_type' => $options['user_type'] ?? 'student',
                'referral_source' => $options['referral_source'] ?? 'direct'
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track user login
     */
    public function trackUserLogin($userId, $options = [])
    {
        $event = [
            'name' => 'user_login',
            'params' => array_merge([
                'user_id' => $userId,
                'login_method' => $options['login_method'] ?? 'email',
                'login_count' => $options['login_count'] ?? 1
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track search
     */
    public function trackSearch($searchTerm, $resultsCount, $userId = null, $options = [])
    {
        $event = [
            'name' => 'search',
            'params' => array_merge([
                'search_term' => $searchTerm,
                'results_count' => $resultsCount,
                'search_type' => $options['search_type'] ?? 'course'
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track video interaction
     */
    public function trackVideoInteraction($videoId, $videoTitle, $action, $userId = null, $options = [])
    {
        $event = [
            'name' => 'video_interaction',
            'params' => array_merge([
                'video_id' => $videoId,
                'video_title' => $videoTitle,
                'action' => $action, // play, pause, complete, seek
                'video_duration' => $options['video_duration'] ?? 0,
                'video_position' => $options['video_position'] ?? 0
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track custom event
     */
    public function trackCustomEvent($eventName, $params = [], $userId = null)
    {
        $event = [
            'name' => $eventName,
            'params' => $params
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Send event to Google Analytics
     */
    private function sendEvent($event, $userId = null)
    {
        if (!$this->measurementId || !$this->apiSecret) {
            Log::warning('Google Analytics not configured');
            return false;
        }

        try {
            $clientId = $this->getClientId($userId);
            
            $payload = [
                'client_id' => $clientId,
                'events' => [$event]
            ];

            // Add user properties if user ID is provided
            if ($userId) {
                $payload['user_id'] = $userId;
            }

            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post($this->endpoint . "?measurement_id={$this->measurementId}&api_secret={$this->apiSecret}", $payload);

            if ($response->successful()) {
                Log::debug('Google Analytics event sent successfully', [
                    'event' => $event['name'],
                    'user_id' => $userId,
                    'response' => $response->status()
                ]);
                return true;
            } else {
                Log::warning('Google Analytics event failed', [
                    'event' => $event['name'],
                    'user_id' => $userId,
                    'response' => $response->body()
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('Google Analytics event error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get or generate client ID
     */
    private function getClientId($userId = null)
    {
        if ($userId) {
            // Use user ID as client ID for authenticated users
            return "user_{$userId}";
        }

        // Generate or retrieve anonymous client ID
        $clientId = Session::get('ga_client_id');
        
        if (!$clientId) {
            $clientId = $this->generateClientId();
            Session::put('ga_client_id', $clientId);
        }

        return $clientId;
    }

    /**
     * Generate unique client ID
     */
    private function generateClientId()
    {
        return uniqid('ga_', true);
    }

    /**
     * Track ecommerce events
     */
    public function trackEcommerce($action, $data, $userId = null)
    {
        switch ($action) {
            case 'add_to_cart':
                return $this->trackAddToCart($data, $userId);
            case 'remove_from_cart':
                return $this->trackRemoveFromCart($data, $userId);
            case 'view_cart':
                return $this->trackViewCart($data, $userId);
            case 'begin_checkout':
                return $this->trackBeginCheckout($data, $userId);
            case 'add_shipping_info':
                return $this->trackAddShippingInfo($data, $userId);
            case 'add_payment_info':
                return $this->trackAddPaymentInfo($data, $userId);
            case 'purchase':
                return $this->trackPurchase($data, $userId);
            case 'refund':
                return $this->trackRefund($data, $userId);
            default:
                return $this->trackCustomEvent($action, $data, $userId);
        }
    }

    /**
     * Track add to cart
     */
    private function trackAddToCart($data, $userId = null)
    {
        $event = [
            'name' => 'add_to_cart',
            'params' => [
                'currency' => $data['currency'] ?? 'TRY',
                'value' => $data['value'] ?? 0,
                'items' => $data['items'] ?? []
            ]
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track remove from cart
     */
    private function trackRemoveFromCart($data, $userId = null)
    {
        $event = [
            'name' => 'remove_from_cart',
            'params' => [
                'currency' => $data['currency'] ?? 'TRY',
                'value' => $data['value'] ?? 0,
                'items' => $data['items'] ?? []
            ]
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track view cart
     */
    private function trackViewCart($data, $userId = null)
    {
        $event = [
            'name' => 'view_cart',
            'params' => [
                'currency' => $data['currency'] ?? 'TRY',
                'value' => $data['value'] ?? 0,
                'items' => $data['items'] ?? []
            ]
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track begin checkout
     */
    private function trackBeginCheckout($data, $userId = null)
    {
        $event = [
            'name' => 'begin_checkout',
            'params' => [
                'currency' => $data['currency'] ?? 'TRY',
                'value' => $data['value'] ?? 0,
                'items' => $data['items'] ?? []
            ]
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track add shipping info
     */
    private function trackAddShippingInfo($data, $userId = null)
    {
        $event = [
            'name' => 'add_shipping_info',
            'params' => [
                'currency' => $data['currency'] ?? 'TRY',
                'value' => $data['value'] ?? 0,
                'shipping_tier' => $data['shipping_tier'] ?? 'standard',
                'items' => $data['items'] ?? []
            ]
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track add payment info
     */
    private function trackAddPaymentInfo($data, $userId = null)
    {
        $event = [
            'name' => 'add_payment_info',
            'params' => [
                'currency' => $data['currency'] ?? 'TRY',
                'value' => $data['value'] ?? 0,
                'payment_type' => $data['payment_type'] ?? 'credit_card',
                'items' => $data['items'] ?? []
            ]
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track purchase
     */
    private function trackPurchase($data, $userId = null)
    {
        $event = [
            'name' => 'purchase',
            'params' => [
                'transaction_id' => $data['transaction_id'],
                'currency' => $data['currency'] ?? 'TRY',
                'value' => $data['value'] ?? 0,
                'tax' => $data['tax'] ?? 0,
                'shipping' => $data['shipping'] ?? 0,
                'items' => $data['items'] ?? []
            ]
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track refund
     */
    private function trackRefund($data, $userId = null)
    {
        $event = [
            'name' => 'refund',
            'params' => [
                'transaction_id' => $data['transaction_id'],
                'currency' => $data['currency'] ?? 'TRY',
                'value' => $data['value'] ?? 0,
                'refund_reason' => $data['refund_reason'] ?? 'other',
                'items' => $data['items'] ?? []
            ]
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track user engagement
     */
    public function trackUserEngagement($userId, $engagementTime = 0, $options = [])
    {
        $event = [
            'name' => 'user_engagement',
            'params' => array_merge([
                'engagement_time_msec' => $engagementTime * 1000, // Convert to milliseconds
                'session_id' => Session::getId()
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track session start
     */
    public function trackSessionStart($userId = null, $options = [])
    {
        $event = [
            'name' => 'session_start',
            'params' => array_merge([
                'session_id' => Session::getId(),
                'session_number' => $options['session_number'] ?? 1
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track session end
     */
    public function trackSessionEnd($userId = null, $options = [])
    {
        $event = [
            'name' => 'session_end',
            'params' => array_merge([
                'session_id' => Session::getId(),
                'session_duration' => $options['session_duration'] ?? 0
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track exception
     */
    public function trackException($description, $fatal = false, $userId = null, $options = [])
    {
        $event = [
            'name' => 'exception',
            'params' => array_merge([
                'description' => $description,
                'fatal' => $fatal
            ], $options)
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Track timing
     */
    public function trackTiming($name, $value, $category = null, $label = null, $userId = null)
    {
        $event = [
            'name' => 'timing_complete',
            'params' => [
                'name' => $name,
                'value' => $value,
                'category' => $category,
                'label' => $label
            ]
        ];

        return $this->sendEvent($event, $userId);
    }

    /**
     * Get analytics configuration
     */
    public function getAnalyticsConfig()
    {
        return [
            'measurement_id' => $this->measurementId,
            'enabled' => !empty($this->measurementId) && !empty($this->apiSecret),
            'debug_mode' => config('app.debug', false),
            'tracking_consent' => config('services.google_analytics.tracking_consent', true)
        ];
    }

    /**
     * Check if analytics is enabled
     */
    public function isEnabled()
    {
        return !empty($this->measurementId) && !empty($this->apiSecret);
    }

    /**
     * Get tracking code
     */
    public function getTrackingCode()
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $code = "
        <!-- Google Analytics -->
        <script async src=\"https://www.googletagmanager.com/gtag/js?id={$this->measurementId}\"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{$this->measurementId}', {
                'custom_map': {
                    'dimension1': 'user_id',
                    'dimension2': 'user_role',
                    'dimension3': 'course_category'
                }
            });
        </script>
        ";

        return $code;
    }

    /**
     * Get enhanced ecommerce tracking code
     */
    public function getEnhancedEcommerceCode()
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $code = "
        <!-- Google Analytics Enhanced Ecommerce -->
        <script>
            gtag('config', '{$this->measurementId}', {
                'send_page_view': false,
                'enhanced_ecommerce': true
            });
        </script>
        ";

        return $code;
    }

    /**
     * Test analytics connection
     */
    public function testConnection()
    {
        if (!$this->isEnabled()) {
            return [
                'status' => 'not_configured',
                'message' => 'Google Analytics not configured'
            ];
        }

        try {
            // Send a test event
            $testEvent = [
                'name' => 'test_event',
                'params' => [
                    'test_param' => 'test_value',
                    'timestamp' => now()->toISOString()
                ]
            ];

            $result = $this->sendEvent($testEvent, 'test_user');

            return [
                'status' => $result ? 'success' : 'failed',
                'message' => $result ? 'Test event sent successfully' : 'Test event failed',
                'measurement_id' => $this->measurementId
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Connection test failed: ' . $e->getMessage()
            ];
        }
    }
} 