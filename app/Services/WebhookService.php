<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class WebhookService
{
    /**
     * Send webhook to all subscribers
     */
    public function sendWebhook($event, $data, $priority = 'normal')
    {
        $webhooks = Webhook::where('event', $event)
            ->where('is_active', true)
            ->get();
        
        $successCount = 0;
        $failureCount = 0;
        
        foreach ($webhooks as $webhook) {
            try {
                $result = $this->sendToWebhook($webhook, $data);
                
                if ($result) {
                    $successCount++;
                    $this->logWebhook($webhook, $event, $data, 'success');
                } else {
                    $failureCount++;
                    $this->logWebhook($webhook, $event, $data, 'failed');
                }
            } catch (\Exception $e) {
                $failureCount++;
                $this->logWebhook($webhook, $event, $data, 'error', $e->getMessage());
                
                Log::error("Webhook error for {$webhook->url}: " . $e->getMessage());
            }
        }
        
        Log::info("Webhook event '{$event}' sent", [
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'total_webhooks' => $webhooks->count()
        ]);
        
        return [
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'total_webhooks' => $webhooks->count()
        ];
    }

    /**
     * Send webhook to specific endpoint
     */
    public function sendToWebhook(Webhook $webhook, $data)
    {
        $payload = $this->buildPayload($webhook, $data);
        $headers = $this->buildHeaders($webhook);
        
        try {
            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->post($webhook->url, $payload);
            
            if ($response->successful()) {
                return true;
            }
            
            Log::warning("Webhook failed with status: {$response->status()}", [
                'webhook_id' => $webhook->id,
                'url' => $webhook->url,
                'response' => $response->body()
            ]);
            
            return false;
        } catch (\Exception $e) {
            Log::error("Webhook exception: " . $e->getMessage(), [
                'webhook_id' => $webhook->id,
                'url' => $webhook->url
            ]);
            
            return false;
        }
    }

    /**
     * Build webhook payload
     */
    private function buildPayload(Webhook $webhook, $data)
    {
        $basePayload = [
            'event' => $webhook->event,
            'timestamp' => now()->toISOString(),
            'webhook_id' => $webhook->id,
            'data' => $data
        ];
        
        // Add custom headers if specified
        if ($webhook->custom_headers) {
            $basePayload['custom_headers'] = $webhook->custom_headers;
        }
        
        // Add signature if enabled
        if ($webhook->signature_enabled && $webhook->secret_key) {
            $basePayload['signature'] = $this->generateSignature($basePayload, $webhook->secret_key);
        }
        
        return $basePayload;
    }

    /**
     * Build webhook headers
     */
    private function buildHeaders(Webhook $webhook)
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'LMS-Platform-Webhook/1.0',
            'X-Webhook-Event' => $webhook->event,
            'X-Webhook-ID' => $webhook->id
        ];
        
        // Add custom headers
        if ($webhook->custom_headers) {
            $customHeaders = json_decode($webhook->custom_headers, true);
            if (is_array($customHeaders)) {
                $headers = array_merge($headers, $customHeaders);
            }
        }
        
        // Add authentication header if specified
        if ($webhook->auth_type === 'bearer' && $webhook->auth_token) {
            $headers['Authorization'] = 'Bearer ' . $webhook->auth_token;
        } elseif ($webhook->auth_type === 'basic' && $webhook->auth_username && $webhook->auth_password) {
            $headers['Authorization'] = 'Basic ' . base64_encode($webhook->auth_username . ':' . $webhook->auth_password);
        }
        
        return $headers;
    }

    /**
     * Generate webhook signature
     */
    private function generateSignature($payload, $secretKey)
    {
        $data = json_encode($payload);
        return hash_hmac('sha256', $data, $secretKey);
    }

    /**
     * Verify webhook signature
     */
    public function verifySignature($payload, $signature, $secretKey)
    {
        $expectedSignature = hash_hmac('sha256', json_encode($payload), $secretKey);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Log webhook attempt
     */
    private function logWebhook(Webhook $webhook, $event, $data, $status, $errorMessage = null)
    {
        WebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'url' => $webhook->url,
            'payload' => json_encode($data),
            'status' => $status,
            'error_message' => $errorMessage,
            'response_time' => null, // Could be added if needed
            'attempted_at' => now()
        ]);
    }

    /**
     * Send course enrollment webhook
     */
    public function sendCourseEnrollmentWebhook($user, $course, $enrollment)
    {
        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ],
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'category' => $course->category->name
            ],
            'enrollment' => [
                'id' => $enrollment->id,
                'enrolled_at' => $enrollment->created_at->toISOString(),
                'status' => $enrollment->status
            ]
        ];
        
        return $this->sendWebhook('course.enrolled', $data);
    }

    /**
     * Send course completion webhook
     */
    public function sendCourseCompletionWebhook($user, $course, $enrollment)
    {
        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ],
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'category' => $course->category->name
            ],
            'completion' => [
                'completed_at' => $enrollment->completed_at->toISOString(),
                'final_score' => $enrollment->final_score,
                'certificate_url' => route('certificates.download', $enrollment->id)
            ]
        ];
        
        return $this->sendWebhook('course.completed', $data);
    }

    /**
     * Send quiz result webhook
     */
    public function sendQuizResultWebhook($user, $quiz, $attempt)
    {
        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ],
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'course_id' => $quiz->course_id
            ],
            'attempt' => [
                'id' => $attempt->id,
                'score' => $attempt->score,
                'passed' => $attempt->passed,
                'completed_at' => $attempt->completed_at->toISOString()
            ]
        ];
        
        return $this->sendWebhook('quiz.completed', $data);
    }

    /**
     * Send payment webhook
     */
    public function sendPaymentWebhook($user, $order, $payment)
    {
        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ],
            'order' => [
                'id' => $order->id,
                'total_amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => $order->status
            ],
            'payment' => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'gateway' => $payment->gateway,
                'transaction_id' => $payment->transaction_id,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at?->toISOString()
            ]
        ];
        
        return $this->sendWebhook('payment.completed', $data);
    }

    /**
     * Send user registration webhook
     */
    public function sendUserRegistrationWebhook($user)
    {
        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'registered_at' => $user->created_at->toISOString()
            ]
        ];
        
        return $this->sendWebhook('user.registered', $data);
    }

    /**
     * Send instructor application webhook
     */
    public function sendInstructorApplicationWebhook($user, $application)
    {
        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ],
            'application' => [
                'id' => $application->id,
                'status' => $application->status,
                'submitted_at' => $application->created_at->toISOString(),
                'bio' => $application->bio,
                'experience' => $application->experience
            ]
        ];
        
        return $this->sendWebhook('instructor.application_submitted', $data);
    }

    /**
     * Send system maintenance webhook
     */
    public function sendSystemMaintenanceWebhook($maintenance)
    {
        $data = [
            'maintenance' => [
                'id' => $maintenance->id,
                'title' => $maintenance->title,
                'description' => $maintenance->description,
                'scheduled_start' => $maintenance->scheduled_start->toISOString(),
                'scheduled_end' => $maintenance->scheduled_end->toISOString(),
                'status' => $maintenance->status
            ]
        ];
        
        return $this->sendWebhook('system.maintenance', $data);
    }

    /**
     * Retry failed webhooks
     */
    public function retryFailedWebhooks($webhookId = null, $limit = 10)
    {
        $query = WebhookLog::where('status', 'failed')
            ->where('attempted_at', '>=', now()->subDays(7))
            ->orderBy('attempted_at', 'asc');
        
        if ($webhookId) {
            $query->where('webhook_id', $webhookId);
        }
        
        $failedLogs = $query->limit($limit)->get();
        $retryCount = 0;
        
        foreach ($failedLogs as $log) {
            $webhook = Webhook::find($log->webhook_id);
            
            if (!$webhook || !$webhook->is_active) {
                continue;
            }
            
            try {
                $data = json_decode($log->payload, true);
                $result = $this->sendToWebhook($webhook, $data);
                
                if ($result) {
                    $log->update(['status' => 'retried_success']);
                    $retryCount++;
                } else {
                    $log->update(['status' => 'retry_failed']);
                }
            } catch (\Exception $e) {
                $log->update([
                    'status' => 'retry_error',
                    'error_message' => $e->getMessage()
                ]);
            }
        }
        
        return $retryCount;
    }

    /**
     * Get webhook statistics
     */
    public function getWebhookStats($period = '24h')
    {
        $startTime = match($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay()
        };
        
        $stats = WebhookLog::where('attempted_at', '>=', $startTime)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');
        
        return [
            'total_webhooks' => $stats->sum('count'),
            'successful' => $stats->get('success')->count ?? 0,
            'failed' => $stats->get('failed')->count ?? 0,
            'retried_success' => $stats->get('retried_success')->count ?? 0,
            'retry_failed' => $stats->get('retry_failed')->count ?? 0,
            'period' => $period,
            'start_time' => $startTime,
            'end_time' => now()
        ];
    }

    /**
     * Clean old webhook logs
     */
    public function cleanOldLogs($days = 90)
    {
        $deletedCount = WebhookLog::where('attempted_at', '<', now()->subDays($days))
            ->delete();
        
        Log::info("Cleaned {$deletedCount} old webhook logs");
        
        return $deletedCount;
    }

    /**
     * Test webhook endpoint
     */
    public function testWebhook(Webhook $webhook)
    {
        $testData = [
            'test' => true,
            'message' => 'This is a test webhook from LMS Platform',
            'timestamp' => now()->toISOString()
        ];
        
        return $this->sendToWebhook($webhook, $testData);
    }

    /**
     * Validate webhook URL
     */
    public function validateWebhookUrl($url)
    {
        try {
            $response = Http::timeout(10)->get($url);
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
} 