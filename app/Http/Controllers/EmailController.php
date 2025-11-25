<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\EmailService;
use App\Models\User;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class EmailController extends Controller
{
    protected $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * Send welcome email to user
     */
    public function sendWelcomeEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::findOrFail($request->user_id);
        
        // Check if user has permission to send emails
        if (!Auth::user()->isAdmin() && Auth::id() !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $result = $this->emailService->sendWelcomeEmail($user);

        return response()->json([
            'success' => $result,
            'message' => $result ? 'Welcome email sent successfully' : 'Failed to send welcome email'
        ]);
    }

    /**
     * Send course enrollment email
     */
    public function sendCourseEnrollmentEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'course_id' => 'required|exists:courses,id',
            'order_id' => 'required|exists:orders,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::findOrFail($request->user_id);
        $course = Course::findOrFail($request->course_id);
        $order = \App\Models\Order::findOrFail($request->order_id);

        $result = $this->emailService->sendCourseEnrollmentEmail($user, $course, $order);

        return response()->json([
            'success' => $result,
            'message' => $result ? 'Course enrollment email sent successfully' : 'Failed to send course enrollment email'
        ]);
    }

    /**
     * Send bulk email to multiple users
     */
    public function sendBulkEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
            'subject' => 'required|string|max:255',
            'template' => 'required|string',
            'data' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Only admins can send bulk emails
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Only admins can send bulk emails'
            ], 403);
        }

        $results = $this->emailService->sendBulkEmail(
            $request->user_ids,
            $request->subject,
            $request->template,
            $request->data ?? []
        );

        return response()->json([
            'success' => true,
            'message' => 'Bulk email operation completed',
            'results' => $results
        ]);
    }

    /**
     * Send instructor notification
     */
    public function sendInstructorNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'instructor_id' => 'required|exists:users,id',
            'subject' => 'required|string|max:255',
            'template' => 'required|string',
            'data' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $instructor = User::findOrFail($request->instructor_id);
        
        // Verify it's actually an instructor
        if (!$instructor->isInstructor()) {
            return response()->json([
                'success' => false,
                'message' => 'User is not an instructor'
            ], 422);
        }

        $result = $this->emailService->sendInstructorNotification(
            $instructor,
            $request->subject,
            $request->template,
            $request->data ?? []
        );

        return response()->json([
            'success' => $result,
            'message' => $result ? 'Instructor notification sent successfully' : 'Failed to send instructor notification'
        ]);
    }

    /**
     * Send system notification to admins
     */
    public function sendSystemNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'template' => 'required|string',
            'data' => 'array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Only admins can send system notifications
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Only admins can send system notifications'
            ], 403);
        }

        $result = $this->emailService->sendAdminNotification(
            $request->subject,
            $request->template,
            $request->data ?? []
        );

        return response()->json([
            'success' => $result,
            'message' => $result ? 'System notification sent successfully' : 'Failed to send system notification'
        ]);
    }

    /**
     * Get email templates list
     */
    public function getEmailTemplates()
    {
        $templates = [
            'welcome' => 'Hoş Geldin Emaili',
            'course-enrollment' => 'Kurs Kayıt Emaili',
            'quiz-result' => 'Quiz Sonuç Emaili',
            'analytics-report' => 'Analitik Rapor Emaili',
            'password-reset' => 'Şifre Sıfırlama Emaili',
            'course-completion' => 'Kurs Tamamlama Emaili',
            'certificate' => 'Sertifika Emaili',
            'reminder' => 'Hatırlatma Emaili'
        ];

        return response()->json([
            'success' => true,
            'templates' => $templates
        ]);
    }

    /**
     * Test email functionality
     */
    public function testEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'template' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Only admins can test emails
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Only admins can test emails'
            ], 403);
        }

        try {
            // Send test email
            \Mail::send('emails.test', ['user' => Auth::user()], function($message) use ($request) {
                $message->to($request->email)->subject('Test Email - LMS Platform');
            });

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully to ' . $request->email
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ], 500);
        }
    }
} 