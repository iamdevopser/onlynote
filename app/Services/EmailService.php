<?php

namespace App\Services;

use App\Models\User;
use App\Models\Course;
use App\Models\Order;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Mail\WelcomeEmail;
use App\Mail\CourseEnrollmentEmail;
use App\Mail\QuizResultEmail;
use App\Mail\AnalyticsReport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailService
{
    /**
     * Send welcome email to new user
     */
    public function sendWelcomeEmail(User $user): bool
    {
        try {
            Mail::to($user->email)->send(new WelcomeEmail($user));
            Log::info("Welcome email sent to: {$user->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send welcome email to {$user->email}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send course enrollment confirmation
     */
    public function sendCourseEnrollmentEmail(User $user, Course $course, Order $order): bool
    {
        try {
            Mail::to($user->email)->send(new CourseEnrollmentEmail($user, $course, $order));
            Log::info("Course enrollment email sent to: {$user->email} for course: {$course->title}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send course enrollment email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send quiz result notification
     */
    public function sendQuizResultEmail(User $user, Quiz $quiz, QuizAttempt $attempt): bool
    {
        try {
            Mail::to($user->email)->send(new QuizResultEmail($user, $quiz, $attempt));
            Log::info("Quiz result email sent to: {$user->email} for quiz: {$quiz->title}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send quiz result email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send analytics report email
     */
    public function sendAnalyticsReport(User $instructor, array $reportData, string $reportType, string $period, string $email): bool
    {
        try {
            Mail::to($email)->send(new AnalyticsReport($instructor, $reportData, $reportType, $period));
            Log::info("Analytics report email sent to: {$email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send analytics report email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send bulk emails to multiple users
     */
    public function sendBulkEmail(array $userIds, string $subject, string $template, array $data = []): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];
        
        $users = User::whereIn('id', $userIds)->get();
        
        foreach ($users as $user) {
            try {
                Mail::send($template, array_merge($data, ['user' => $user]), function($message) use ($user, $subject) {
                    $message->to($user->email)->subject($subject);
                });
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "User {$user->id}: " . $e->getMessage();
                Log::error("Bulk email failed for user {$user->id}: " . $e->getMessage());
            }
        }
        
        return $results;
    }

    /**
     * Send notification to course instructor
     */
    public function sendInstructorNotification(User $instructor, string $subject, string $template, array $data = []): bool
    {
        try {
            Mail::send($template, array_merge($data, ['instructor' => $instructor]), function($message) use ($instructor, $subject) {
                $message->to($instructor->email)->subject($subject);
            });
            Log::info("Instructor notification sent to: {$instructor->email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send instructor notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send system notification to admin
     */
    public function sendAdminNotification(string $subject, string $template, array $data = []): bool
    {
        try {
            $adminEmails = User::where('role', 'admin')->pluck('email')->toArray();
            
            if (empty($adminEmails)) {
                Log::warning("No admin users found for system notification");
                return false;
            }
            
            Mail::send($template, $data, function($message) use ($adminEmails, $subject) {
                $message->to($adminEmails)->subject($subject);
            });
            
            Log::info("System notification sent to admins");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send system notification: " . $e->getMessage());
            return false;
        }
    }
} 