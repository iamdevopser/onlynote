<?php

namespace App\Services;

use App\Models\CourseAnalytics;
use App\Models\EarningsAnalytics;
use App\Models\UserEngagement;
use App\Models\Course;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class AnalyticsService
{
    /**
     * Kurs ziyaret verilerini kaydet
     */
    public function recordCourseView($courseId, $instructorId)
    {
        $today = Carbon::today();
        
        $analytics = CourseAnalytics::firstOrCreate([
            'course_id' => $courseId,
            'instructor_id' => $instructorId,
            'date' => $today
        ]);

        $analytics->increment('views');
        
        return $analytics;
    }

    /**
     * Kazanç verilerini kaydet
     */
    public function recordEarning($instructorId, $courseId, $amount, $paymentType = 'sale')
    {
        $today = Carbon::today();
        
        $analytics = EarningsAnalytics::firstOrCreate([
            'instructor_id' => $instructorId,
            'course_id' => $courseId,
            'date' => $today,
            'payment_type' => $paymentType
        ]);

        $analytics->increment('total_earnings', $amount);
        $analytics->increment('order_count');
        
        return $analytics;
    }

    /**
     * Kullanıcı etkileşimini kaydet
     */
    public function recordEngagement($courseId, $userId, $instructorId, $type, $value = null, $meta = [])
    {
        return UserEngagement::create([
            'course_id' => $courseId,
            'user_id' => $userId,
            'instructor_id' => $instructorId,
            'engagement_type' => $type,
            'engagement_value' => $value,
            'date' => Carbon::today(),
            'meta' => $meta
        ]);
    }

    /**
     * Instructor için kazanç raporu al
     */
    public function getEarningsReport($instructorId, $startDate = null, $endDate = null)
    {
        $query = EarningsAnalytics::where('instructor_id', $instructorId);
        
        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }
        
        return $query->orderBy('date')->get();
    }

    /**
     * Instructor için ziyaret raporu al
     */
    public function getVisitsReport($instructorId, $startDate = null, $endDate = null)
    {
        $query = CourseAnalytics::where('instructor_id', $instructorId);
        
        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }
        
        return $query->orderBy('date')->get();
    }

    /**
     * Instructor için etkileşim raporu al
     */
    public function getEngagementReport($instructorId, $startDate = null, $endDate = null)
    {
        $query = UserEngagement::where('instructor_id', $instructorId);
        
        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }
        
        return $query->orderBy('date')->get();
    }

    /**
     * Toplam istatistikleri al
     */
    public function getTotalStats($instructorId)
    {
        $totalEarnings = EarningsAnalytics::where('instructor_id', $instructorId)->sum('total_earnings');
        $totalViews = CourseAnalytics::where('instructor_id', $instructorId)->sum('views');
        $totalEngagements = UserEngagement::where('instructor_id', $instructorId)->count();
        
        return [
            'total_earnings' => $totalEarnings,
            'total_views' => $totalViews,
            'total_engagements' => $totalEngagements
        ];
    }
} 