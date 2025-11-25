<?php

namespace App\Services;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PushNotificationService
{
    protected $firebaseKey;
    protected $firebaseEndpoint = 'https://fcm.googleapis.com/fcm/send';
    
    public function __construct()
    {
        $this->firebaseKey = config('services.firebase.server_key');
    }

    /**
     * Send push notification to user
     */
    public function sendToUser(User $user, $title, $body, $data = [])
    {
        if (!$user->fcm_token) {
            return false;
        }
        
        $notification = [
            'title' => $title,
            'body' => $body,
            'icon' => asset('images/notification-icon.png'),
            'click_action' => $data['click_action'] ?? '/dashboard',
            'badge' => $this->getUserBadgeCount($user),
            'sound' => 'default'
        ];
        
        $message = [
            'to' => $user->fcm_token,
            'notification' => $notification,
            'data' => $data,
            'priority' => 'high',
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'priority' => 'high',
                    'default_sound' => true,
                    'default_vibrate_timings' => true,
                    'default_light_settings' => true
                ]
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10'
                ],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'badge' => $this->getUserBadgeCount($user)
                    ]
                ]
            ]
        ];
        
        return $this->sendToFirebase($message);
    }

    /**
     * Send push notification to multiple users
     */
    public function sendToUsers($userIds, $title, $body, $data = [])
    {
        $users = User::whereIn('id', $userIds)
            ->whereNotNull('fcm_token')
            ->get();
        
        $tokens = $users->pluck('fcm_token')->toArray();
        
        if (empty($tokens)) {
            return false;
        }
        
        // Split tokens into chunks of 1000 (FCM limit)
        $tokenChunks = array_chunk($tokens, 1000);
        $successCount = 0;
        
        foreach ($tokenChunks as $chunk) {
            $message = [
                'registration_ids' => $chunk,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'icon' => asset('images/notification-icon.png'),
                    'click_action' => $data['click_action'] ?? '/dashboard',
                    'sound' => 'default'
                ],
                'data' => $data,
                'priority' => 'high'
            ];
            
            if ($this->sendToFirebase($message)) {
                $successCount += count($chunk);
            }
        }
        
        return $successCount;
    }

    /**
     * Send push notification to topic subscribers
     */
    public function sendToTopic($topic, $title, $body, $data = [])
    {
        $message = [
            'to' => "/topics/{$topic}",
            'notification' => [
                'title' => $title,
                'body' => $body,
                'icon' => asset('images/notification-icon.png'),
                'click_action' => $data['click_action'] ?? '/dashboard',
                'sound' => 'default'
            ],
            'data' => $data,
            'priority' => 'high'
        ];
        
        return $this->sendToFirebase($message);
    }

    /**
     * Subscribe user to topic
     */
    public function subscribeToTopic($user, $topic)
    {
        if (!$user->fcm_token) {
            return false;
        }
        
        $endpoint = "https://iid.googleapis.com/iid/v1/{$user->fcm_token}/rel/topics/{$topic}";
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $this->firebaseKey,
                'Content-Type' => 'application/json'
            ])->post($endpoint);
            
            if ($response->successful()) {
                Log::info("User {$user->id} subscribed to topic: {$topic}");
                return true;
            }
            
            Log::error("Failed to subscribe user to topic", [
                'user_id' => $user->id,
                'topic' => $topic,
                'response' => $response->body()
            ]);
            
            return false;
        } catch (\Exception $e) {
            Log::error("Error subscribing user to topic: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unsubscribe user from topic
     */
    public function unsubscribeFromTopic($user, $topic)
    {
        if (!$user->fcm_token) {
            return false;
        }
        
        // Note: FCM doesn't support unsubscribing from topics via API
        // Users need to manually unsubscribe or the app needs to handle this
        Log::info("User {$user->id} unsubscribed from topic: {$topic}");
        
        return true;
    }

    /**
     * Send course enrollment notification
     */
    public function sendCourseEnrollmentNotification(User $user, $course)
    {
        $title = 'Yeni Kurs Kaydı';
        $body = "{$course->title} kursuna başarıyla kayıt oldunuz!";
        
        $data = [
            'type' => 'course_enrollment',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'click_action' => "/courses/{$course->id}"
        ];
        
        return $this->sendToUser($user, $title, $body, $data);
    }

    /**
     * Send quiz result notification
     */
    public function sendQuizResultNotification(User $user, $quiz, $score, $passed)
    {
        $result = $passed ? 'Başarılı' : 'Başarısız';
        $title = 'Quiz Sonucu';
        $body = "{$quiz->title} quiz'inde {$result} oldunuz. Puanınız: {$score}";
        
        $data = [
            'type' => 'quiz_result',
            'quiz_id' => $quiz->id,
            'quiz_title' => $quiz->title,
            'score' => $score,
            'passed' => $passed,
            'click_action' => "/quizzes/{$quiz->id}/result"
        ];
        
        return $this->sendToUser($user, $title, $body, $data);
    }

    /**
     * Send course completion notification
     */
    public function sendCourseCompletionNotification(User $user, $course)
    {
        $title = 'Kurs Tamamlandı!';
        $body = "Tebrikler! {$course->title} kursunu başarıyla tamamladınız.";
        
        $data = [
            'type' => 'course_completion',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'click_action' => "/courses/{$course->id}/certificate"
        ];
        
        return $this->sendToUser($user, $title, $body, $data);
    }

    /**
     * Send new course notification
     */
    public function sendNewCourseNotification($course)
    {
        $title = 'Yeni Kurs Eklendi';
        $body = "{$course->title} adında yeni bir kurs eklendi!";
        
        $data = [
            'type' => 'new_course',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'click_action' => "/courses/{$course->id}"
        ];
        
        // Send to all users or specific category subscribers
        $users = User::where('role', 'user')
            ->where('notifications_enabled', true)
            ->get();
        
        return $this->sendToUsers($users->pluck('id')->toArray(), $title, $body, $data);
    }

    /**
     * Send system maintenance notification
     */
    public function sendSystemMaintenanceNotification($title, $body, $scheduledTime = null)
    {
        $data = [
            'type' => 'system_maintenance',
            'scheduled_time' => $scheduledTime,
            'click_action' => '/maintenance'
        ];
        
        if ($scheduledTime) {
            $body .= " Planlanan zaman: {$scheduledTime}";
        }
        
        // Send to all users
        $users = User::where('notifications_enabled', true)->get();
        
        return $this->sendToUsers($users->pluck('id')->toArray(), $title, $body, $data);
    }

    /**
     * Send promotional notification
     */
    public function sendPromotionalNotification($title, $body, $promoCode = null, $expiryDate = null)
    {
        $data = [
            'type' => 'promotional',
            'promo_code' => $promoCode,
            'expiry_date' => $expiryDate,
            'click_action' => '/promotions'
        ];
        
        if ($promoCode) {
            $body .= " Promosyon kodu: {$promoCode}";
        }
        
        if ($expiryDate) {
            $body .= " Son kullanım: {$expiryDate}";
        }
        
        // Send to all users
        $users = User::where('notifications_enabled', true)->get();
        
        return $this->sendToUsers($users->pluck('id')->toArray(), $title, $body, $data);
    }

    /**
     * Send to Firebase
     */
    private function sendToFirebase($message)
    {
        if (!$this->firebaseKey) {
            Log::error('Firebase server key not configured');
            return false;
        }
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $this->firebaseKey,
                'Content-Type' => 'application/json'
            ])->post($this->firebaseEndpoint, $message);
            
            if ($response->successful()) {
                $result = $response->json();
                
                if (isset($result['success']) && $result['success'] > 0) {
                    Log::info('Push notification sent successfully', [
                        'success_count' => $result['success'],
                        'failure_count' => $result['failure'] ?? 0
                    ]);
                    
                    return true;
                } else {
                    Log::warning('Push notification failed', $result);
                    return false;
                }
            }
            
            Log::error('Firebase API error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            
            return false;
        } catch (\Exception $e) {
            Log::error('Error sending push notification: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user badge count
     */
    private function getUserBadgeCount(User $user)
    {
        return $user->notifications()
            ->where('read_at', null)
            ->count();
    }

    /**
     * Update user FCM token
     */
    public function updateUserToken(User $user, $token)
    {
        $user->update(['fcm_token' => $token]);
        
        Log::info("FCM token updated for user {$user->id}");
        return true;
    }

    /**
     * Remove user FCM token
     */
    public function removeUserToken(User $user)
    {
        $user->update(['fcm_token' => null]);
        
        Log::info("FCM token removed for user {$user->id}");
        return true;
    }

    /**
     * Get notification statistics
     */
    public function getNotificationStats($period = '24h')
    {
        $startTime = match($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay()
        };
        
        $stats = Notification::where('created_at', '>=', $startTime)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->get()
            ->keyBy('type');
        
        return [
            'total_notifications' => $stats->sum('count'),
            'push_notifications' => $stats->get('push')->count ?? 0,
            'email_notifications' => $stats->get('email')->count ?? 0,
            'sms_notifications' => $stats->get('sms')->count ?? 0,
            'period' => $period,
            'start_time' => $startTime,
            'end_time' => now()
        ];
    }

    /**
     * Clean old notifications
     */
    public function cleanOldNotifications($days = 90)
    {
        $deletedCount = Notification::where('created_at', '<', now()->subDays($days))
            ->delete();
        
        Log::info("Cleaned {$deletedCount} old notifications");
        
        return $deletedCount;
    }

    /**
     * Test push notification
     */
    public function testNotification(User $user)
    {
        $title = 'Test Bildirimi';
        $body = 'Bu bir test bildirimidir.';
        
        $data = [
            'type' => 'test',
            'timestamp' => now()->toISOString(),
            'click_action' => '/dashboard'
        ];
        
        return $this->sendToUser($user, $title, $body, $data);
    }
} 