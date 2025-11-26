<?php

namespace App\Services;

use App\Models\User;
use App\Models\SMSLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SMSService
{
    protected $twilioAccountSid;
    protected $twilioAuthToken;
    protected $twilioPhoneNumber;
    protected $twilioEndpoint;
    
    public function __construct()
    {
        $this->twilioAccountSid = config('services.twilio.account_sid');
        $this->twilioAuthToken = config('services.twilio.auth_token');
        $this->twilioPhoneNumber = config('services.twilio.phone_number');
        $this->twilioEndpoint = "https://api.twilio.com/2010-04-01/Accounts/{$this->twilioAccountSid}/Messages.json";
    }

    /**
     * Send SMS to user
     */
    public function sendToUser(User $user, $message, $template = null, $data = [])
    {
        if (!$user->phone_number) {
            Log::warning("User {$user->id} has no phone number");
            return false;
        }

        try {
            // Format message with template if provided
            $formattedMessage = $this->formatMessage($message, $template, $data);
            
            // Send SMS via Twilio
            $response = $this->sendViaTwilio($user->phone_number, $formattedMessage);
            
            if ($response['success']) {
                // Log successful SMS
                $this->logSMS($user, $formattedMessage, 'success', $response['message_sid']);
                
                return true;
            } else {
                // Log failed SMS
                $this->logSMS($user, $formattedMessage, 'failed', null, $response['error']);
                
                return false;
            }
            
        } catch (\Exception $e) {
            Log::error("SMS sending failed for user {$user->id}: " . $e->getMessage());
            
            // Log error
            $this->logSMS($user, $message, 'error', null, $e->getMessage());
            
            return false;
        }
    }

    /**
     * Send SMS to multiple users
     */
    public function sendToUsers($userIds, $message, $template = null, $data = [])
    {
        $users = User::whereIn('id', $userIds)
            ->whereNotNull('phone_number')
            ->where('sms_notifications_enabled', true)
            ->get();

        $successCount = 0;
        $failureCount = 0;

        foreach ($users as $user) {
            if ($this->sendToUser($user, $message, $template, $data)) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        Log::info("Bulk SMS sent", [
            'total_users' => $users->count(),
            'success_count' => $successCount,
            'failure_count' => $failureCount
        ]);

        return [
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'total_users' => $users->count()
        ];
    }

    /**
     * Send SMS to phone number
     */
    public function sendToPhone($phoneNumber, $message)
    {
        try {
            $response = $this->sendViaTwilio($phoneNumber, $message);
            
            if ($response['success']) {
                // Log successful SMS
                $this->logSMS(null, $message, 'success', $response['message_sid'], null, $phoneNumber);
                
                return true;
            } else {
                // Log failed SMS
                $this->logSMS(null, $message, 'failed', null, $response['error'], $phoneNumber);
                
                return false;
            }
            
        } catch (\Exception $e) {
            Log::error("SMS sending failed to {$phoneNumber}: " . $e->getMessage());
            
            // Log error
            $this->logSMS(null, $message, 'error', null, $e->getMessage(), $phoneNumber);
            
            return false;
        }
    }

    /**
     * Send course enrollment SMS
     */
    public function sendCourseEnrollmentSMS(User $user, $course)
    {
        $message = "Kurs kaydınız başarılı! {$course->title} kursuna hoş geldiniz. Kurs detayları: " . route('courses.show', $course->id);
        
        return $this->sendToUser($user, $message, 'course_enrollment', [
            'course_title' => $course->title,
            'course_url' => route('courses.show', $course->id)
        ]);
    }

    /**
     * Send quiz result SMS
     */
    public function sendQuizResultSMS(User $user, $quiz, $score, $passed)
    {
        $result = $passed ? 'Başarılı' : 'Başarısız';
        $message = "Quiz sonucunuz: {$result}! {$quiz->title} quiz'inde puanınız: {$score}";
        
        return $this->sendToUser($user, $message, 'quiz_result', [
            'quiz_title' => $quiz->title,
            'score' => $score,
            'passed' => $passed
        ]);
    }

    /**
     * Send course completion SMS
     */
    public function sendCourseCompletionSMS(User $user, $course)
    {
        $message = "Tebrikler! {$course->title} kursunu başarıyla tamamladınız. Sertifikanızı indirmek için: " . route('certificates.download', $course->id);
        
        return $this->sendToUser($user, $message, 'course_completion', [
            'course_title' => $course->title,
            'certificate_url' => route('certificates.download', $course->id)
        ]);
    }

    /**
     * Send payment confirmation SMS
     */
    public function sendPaymentConfirmationSMS(User $user, $order, $amount)
    {
        $message = "Ödemeniz alındı! Sipariş #{$order->id} için {$amount} TL ödeme başarılı. Teşekkürler!";
        
        return $this->sendToUser($user, $message, 'payment_confirmation', [
            'order_id' => $order->id,
            'amount' => $amount
        ]);
    }

    /**
     * Send system maintenance SMS
     */
    public function sendSystemMaintenanceSMS($phoneNumbers, $title, $body, $scheduledTime = null)
    {
        $message = "Sistem Bakımı: {$title}. {$body}";
        
        if ($scheduledTime) {
            $message .= " Planlanan zaman: {$scheduledTime}";
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($phoneNumbers as $phoneNumber) {
            if ($this->sendToPhone($phoneNumber, $message)) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        return [
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'total_sent' => count($phoneNumbers)
        ];
    }

    /**
     * Send promotional SMS
     */
    public function sendPromotionalSMS($phoneNumbers, $title, $body, $promoCode = null, $expiryDate = null)
    {
        $message = "Promosyon: {$title}. {$body}";
        
        if ($promoCode) {
            $message .= " Kod: {$promoCode}";
        }
        
        if ($expiryDate) {
            $message .= " Son kullanım: {$expiryDate}";
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($phoneNumbers as $phoneNumber) {
            if ($this->sendToPhone($phoneNumber, $message)) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        return [
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'total_sent' => count($phoneNumbers)
        ];
    }

    /**
     * Send via Twilio
     */
    private function sendViaTwilio($phoneNumber, $message)
    {
        if (!$this->twilioAccountSid || !$this->twilioAuthToken) {
            throw new \Exception('Twilio credentials not configured');
        }

        try {
            $response = Http::withBasicAuth($this->twilioAccountSid, $this->twilioAuthToken)
                ->asForm()
                ->post($this->twilioEndpoint, [
                    'To' => $this->formatPhoneNumber($phoneNumber),
                    'From' => $this->twilioPhoneNumber,
                    'Body' => $message
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                return [
                    'success' => true,
                    'message_sid' => $responseData['sid'],
                    'status' => $responseData['status']
                ];
            } else {
                $errorData = $response->json();
                
                return [
                    'success' => false,
                    'error' => $errorData['message'] ?? 'Twilio API error'
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Twilio request failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Format phone number for Twilio
     */
    private function formatPhoneNumber($phoneNumber)
    {
        // Remove all non-digit characters
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Add country code if not present
        if (strlen($cleaned) === 10 && substr($cleaned, 0, 1) !== '1') {
            $cleaned = '1' . $cleaned; // US numbers
        } elseif (strlen($cleaned) === 11 && substr($cleaned, 0, 2) === '90') {
            // Turkish number, already formatted
        } elseif (strlen($cleaned) === 10 && substr($cleaned, 0, 1) === '5') {
            $cleaned = '90' . $cleaned; // Turkish mobile
        }
        
        return '+' . $cleaned;
    }

    /**
     * Format message with template
     */
    private function formatMessage($message, $template = null, $data = [])
    {
        if (!$template) {
            return $message;
        }

        $templates = [
            'course_enrollment' => "Kurs kaydınız başarılı! {course_title} kursuna hoş geldiniz. Detaylar: {course_url}",
            'quiz_result' => "Quiz sonucunuz: {result}! {quiz_title} quiz'inde puanınız: {score}",
            'course_completion' => "Tebrikler! {course_title} kursunu başarıyla tamamladınız. Sertifika: {certificate_url}",
            'payment_confirmation' => "Ödemeniz alındı! Sipariş #{order_id} için {amount} TL ödeme başarılı.",
            'system_maintenance' => "Sistem Bakımı: {title}. {body}",
            'promotional' => "Promosyon: {title}. {body} {promo_code} {expiry_date}"
        ];

        $templateMessage = $templates[$template] ?? $message;
        
        // Replace placeholders with actual data
        foreach ($data as $key => $value) {
            $templateMessage = str_replace('{' . $key . '}', $value, $templateMessage);
        }

        return $templateMessage;
    }

    /**
     * Log SMS
     */
    private function logSMS($user, $message, $status, $messageSid = null, $error = null, $phoneNumber = null)
    {
        SMSLog::create([
            'user_id' => $user ? $user->id : null,
            'phone_number' => $phoneNumber ?: ($user ? $user->phone_number : null),
            'message' => $message,
            'status' => $status,
            'message_sid' => $messageSid,
            'error_message' => $error,
            'sent_at' => now()
        ]);
    }

    /**
     * Get SMS statistics
     */
    public function getSMSStats($period = '24h')
    {
        $startTime = match($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay()
        };

        $stats = SMSLog::where('sent_at', '>=', $startTime)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'total_sms' => $stats->sum('count'),
            'successful' => $stats->get('success')->count ?? 0,
            'failed' => $stats->get('failed')->count ?? 0,
            'errors' => $stats->get('error')->count ?? 0,
            'period' => $period,
            'start_time' => $startTime,
            'end_time' => now()
        ];
    }

    /**
     * Get SMS logs
     */
    public function getSMSLogs($filters = [], $limit = 100)
    {
        $query = SMSLog::with('user');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['phone_number'])) {
            $query->where('phone_number', 'like', '%' . $filters['phone_number'] . '%');
        }

        if (isset($filters['date_from'])) {
            $query->where('sent_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('sent_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('sent_at', 'desc')
                    ->limit($limit)
                    ->get();
    }

    /**
     * Clean old SMS logs
     */
    public function cleanOldLogs($days = 90)
    {
        $deletedCount = SMSLog::where('sent_at', '<', now()->subDays($days))
            ->delete();

        Log::info("Cleaned {$deletedCount} old SMS logs");

        return $deletedCount;
    }

    /**
     * Export SMS logs
     */
    public function exportSMSLogs($filters = [], $format = 'csv')
    {
        $logs = $this->getSMSLogs($filters, 10000); // Export up to 10k records

        if ($format === 'json') {
            return response()->json($logs);
        }

        // CSV export
        $filename = "sms_logs_" . now()->format('Y-m-d_H-i-s') . ".csv";

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

    /**
     * Test SMS sending
     */
    public function testSMS($phoneNumber)
    {
        $message = "Bu bir test SMS'idir. LMS Platform - " . now()->format('Y-m-d H:i:s');
        
        return $this->sendToPhone($phoneNumber, $message);
    }

    /**
     * Check Twilio account status
     */
    public function checkTwilioStatus()
    {
        if (!$this->twilioAccountSid || !$this->twilioAuthToken) {
            return [
                'status' => 'not_configured',
                'message' => 'Twilio credentials not configured'
            ];
        }

        try {
            $response = Http::withBasicAuth($this->twilioAccountSid, $this->twilioAuthToken)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$this->twilioAccountSid}.json");

            if ($response->successful()) {
                $accountData = $response->json();
                
                return [
                    'status' => 'active',
                    'account_name' => $accountData['friendly_name'],
                    'account_type' => $accountData['type'],
                    'balance' => $accountData['balance'],
                    'currency' => $accountData['currency']
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Failed to connect to Twilio API'
                ];
            }

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Twilio connection failed: ' . $e->getMessage()
            ];
        }
    }
} 