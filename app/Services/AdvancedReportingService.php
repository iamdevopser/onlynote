<?php

namespace App\Services;

use App\Models\User;
use App\Models\Course;
use App\Models\Order;
use App\Models\QuizAttempt;
use App\Models\CourseProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class AdvancedReportingService
{
    /**
     * Get comprehensive dashboard report
     */
    public function getDashboardReport($period = 'month')
    {
        $cacheKey = "dashboard_report_{$period}_" . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () use ($period) {
            $startDate = $this->getStartDate($period);
            
            return [
                'overview' => $this->getOverviewStats($startDate),
                'revenue' => $this->getRevenueStats($startDate),
                'courses' => $this->getCourseStats($startDate),
                'users' => $this->getUserStats($startDate),
                'engagement' => $this->getEngagementStats($startDate),
                'performance' => $this->getPerformanceStats($startDate)
            ];
        });
    }

    /**
     * Get instructor performance report
     */
    public function getInstructorReport($instructorId, $period = 'month')
    {
        $cacheKey = "instructor_report_{$instructorId}_{$period}_" . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, 7200, function () use ($instructorId, $period) {
            $startDate = $this->getStartDate($period);
            $instructor = User::find($instructorId);
            
            if (!$instructor || $instructor->role !== 'instructor') {
                return null;
            }
            
            return [
                'instructor' => [
                    'id' => $instructor->id,
                    'name' => $instructor->name,
                    'email' => $instructor->email,
                    'joined_at' => $instructor->created_at
                ],
                'courses' => $this->getInstructorCourseStats($instructor, $startDate),
                'earnings' => $this->getInstructorEarnings($instructor, $startDate),
                'students' => $this->getInstructorStudentStats($instructor, $startDate),
                'ratings' => $this->getInstructorRatingStats($instructor, $startDate),
                'engagement' => $this->getInstructorEngagementStats($instructor, $startDate)
            ];
        });
    }

    /**
     * Get course performance report
     */
    public function getCourseReport($courseId, $period = 'month')
    {
        $cacheKey = "course_report_{$courseId}_{$period}_" . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, 7200, function () use ($courseId, $period) {
            $startDate = $this->getStartDate($period);
            $course = Course::find($courseId);
            
            if (!$course) {
                return null;
            }
            
            return [
                'course' => [
                    'id' => $course->id,
                    'title' => $course->title,
                    'category' => $course->category->name,
                    'instructor' => $course->instructor->name,
                    'created_at' => $course->created_at
                ],
                'enrollments' => $this->getCourseEnrollmentStats($course, $startDate),
                'completion' => $this->getCourseCompletionStats($course, $startDate),
                'revenue' => $this->getCourseRevenueStats($course, $startDate),
                'ratings' => $this->getCourseRatingStats($course, $startDate),
                'engagement' => $this->getCourseEngagementStats($course, $startDate)
            ];
        });
    }

    /**
     * Get learning analytics report
     */
    public function getLearningAnalyticsReport($userId = null, $period = 'month')
    {
        $cacheKey = "learning_analytics_{$userId}_{$period}_" . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () use ($userId, $period) {
            $startDate = $this->getStartDate($period);
            
            if ($userId) {
                $user = User::find($userId);
                return $this->getUserLearningAnalytics($user, $startDate);
            }
            
            return $this->getGlobalLearningAnalytics($startDate);
        });
    }

    /**
     * Get financial report
     */
    public function getFinancialReport($period = 'month')
    {
        $cacheKey = "financial_report_{$period}_" . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, 3600, function () use ($period) {
            $startDate = $this->getStartDate($period);
            
            return [
                'revenue' => $this->getRevenueBreakdown($startDate),
                'expenses' => $this->getExpenseBreakdown($startDate),
                'profit' => $this->getProfitAnalysis($startDate),
                'subscriptions' => $this->getSubscriptionStats($startDate),
                'refunds' => $this->getRefundStats($startDate),
                'projections' => $this->getFinancialProjections($startDate)
            ];
        });
    }

    /**
     * Get start date based on period
     */
    private function getStartDate($period)
    {
        return match($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth()
        };
    }

    /**
     * Get overview statistics
     */
    private function getOverviewStats($startDate)
    {
        return [
            'total_users' => User::count(),
            'total_courses' => Course::count(),
            'total_orders' => Order::where('created_at', '>=', $startDate)->count(),
            'total_revenue' => Order::where('created_at', '>=', $startDate)->sum('total_amount'),
            'active_users' => User::where('last_login_at', '>=', $startDate)->count(),
            'new_users' => User::where('created_at', '>=', $startDate)->count(),
            'new_courses' => Course::where('created_at', '>=', $startDate)->count(),
            'completion_rate' => $this->calculateCompletionRate($startDate)
        ];
    }

    /**
     * Get revenue statistics
     */
    private function getRevenueStats($startDate)
    {
        $revenueData = Order::where('created_at', '>=', $startDate)
            ->where('status', 'completed')
            ->selectRaw('DATE(created_at) as date, SUM(total_amount) as daily_revenue, COUNT(*) as orders')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        
        return [
            'total_revenue' => $revenueData->sum('daily_revenue'),
            'average_order_value' => $revenueData->avg('daily_revenue'),
            'total_orders' => $revenueData->sum('orders'),
            'daily_revenue' => $revenueData->pluck('daily_revenue', 'date'),
            'revenue_growth' => $this->calculateGrowthRate('orders', 'total_amount', $startDate),
            'top_revenue_courses' => $this->getTopRevenueCourses($startDate)
        ];
    }

    /**
     * Get course statistics
     */
    private function getCourseStats($startDate)
    {
        return [
            'total_courses' => Course::count(),
            'active_courses' => Course::where('status', 'published')->count(),
            'new_courses' => Course::where('created_at', '>=', $startDate)->count(),
            'popular_courses' => $this->getPopularCourses($startDate),
            'category_distribution' => $this->getCategoryDistribution(),
            'completion_rates' => $this->getCourseCompletionRates($startDate),
            'average_ratings' => $this->getAverageCourseRatings($startDate)
        ];
    }

    /**
     * Get user statistics
     */
    private function getUserStats($startDate)
    {
        return [
            'total_users' => User::count(),
            'active_users' => User::where('last_login_at', '>=', $startDate)->count(),
            'new_users' => User::where('created_at', '>=', $startDate)->count(),
            'user_growth' => $this->calculateGrowthRate('users', 'id', $startDate),
            'role_distribution' => $this->getRoleDistribution(),
            'geographic_distribution' => $this->getGeographicDistribution($startDate),
            'engagement_levels' => $this->getUserEngagementLevels($startDate)
        ];
    }

    /**
     * Get engagement statistics
     */
    private function getEngagementStats($startDate)
    {
        return [
            'average_session_duration' => $this->calculateAverageSessionDuration($startDate),
            'page_views' => $this->getPageViewStats($startDate),
            'course_progress' => $this->getCourseProgressStats($startDate),
            'quiz_participation' => $this->getQuizParticipationStats($startDate),
            'social_interactions' => $this->getSocialInteractionStats($startDate),
            'mobile_usage' => $this->getMobileUsageStats($startDate)
        ];
    }

    /**
     * Get performance statistics
     */
    private function getPerformanceStats($startDate)
    {
        return [
            'system_performance' => $this->getSystemPerformanceStats($startDate),
            'response_times' => $this->getResponseTimeStats($startDate),
            'error_rates' => $this->getErrorRateStats($startDate),
            'uptime' => $this->getUptimeStats($startDate),
            'resource_usage' => $this->getResourceUsageStats($startDate)
        ];
    }

    /**
     * Calculate completion rate
     */
    private function calculateCompletionRate($startDate)
    {
        $totalEnrollments = CourseProgress::where('created_at', '>=', $startDate)->count();
        $completedEnrollments = CourseProgress::where('created_at', '>=', $startDate)
            ->where('completion_rate', 100)
            ->count();
        
        return $totalEnrollments > 0 ? round(($completedEnrollments / $totalEnrollments) * 100, 2) : 0;
    }

    /**
     * Calculate growth rate
     */
    private function calculateGrowthRate($table, $column, $startDate)
    {
        $currentPeriod = DB::table($table)->where('created_at', '>=', $startDate)->count();
        $previousPeriod = DB::table($table)
            ->whereBetween('created_at', [
                $startDate->copy()->subDays($startDate->diffInDays(now())),
                $startDate
            ])
            ->count();
        
        if ($previousPeriod == 0) {
            return $currentPeriod > 0 ? 100 : 0;
        }
        
        return round((($currentPeriod - $previousPeriod) / $previousPeriod) * 100, 2);
    }

    /**
     * Get top revenue courses
     */
    private function getTopRevenueCourses($startDate)
    {
        return Course::join('orders', 'courses.id', '=', 'orders.course_id')
            ->where('orders.created_at', '>=', $startDate)
            ->where('orders.status', 'completed')
            ->selectRaw('courses.id, courses.title, SUM(orders.total_amount) as total_revenue, COUNT(orders.id) as orders')
            ->groupBy('courses.id', 'courses.title')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get();
    }

    /**
     * Get popular courses
     */
    private function getPopularCourses($startDate)
    {
        return Course::join('course_progress', 'courses.id', '=', 'course_progress.course_id')
            ->where('course_progress.created_at', '>=', $startDate)
            ->selectRaw('courses.id, courses.title, COUNT(course_progress.id) as enrollments')
            ->groupBy('courses.id', 'courses.title')
            ->orderByDesc('enrollments')
            ->limit(10)
            ->get();
    }

    /**
     * Get category distribution
     */
    private function getCategoryDistribution()
    {
        return DB::table('courses')
            ->join('categories', 'courses.category_id', '=', 'categories.id')
            ->selectRaw('categories.name, COUNT(courses.id) as course_count')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('course_count')
            ->get();
    }

    /**
     * Get role distribution
     */
    private function getRoleDistribution()
    {
        return DB::table('users')
            ->selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->get();
    }

    /**
     * Get geographic distribution
     */
    private function getGeographicDistribution($startDate)
    {
        return DB::table('users')
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('country')
            ->selectRaw('country, COUNT(*) as count')
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(10)
            ->get();
    }

    /**
     * Get user engagement levels
     */
    private function getUserEngagementLevels($startDate)
    {
        $users = User::withCount(['enrollments', 'quizAttempts'])
            ->where('created_at', '>=', $startDate)
            ->get();
        
        $levels = [
            'high' => 0,
            'medium' => 0,
            'low' => 0
        ];
        
        foreach ($users as $user) {
            $score = ($user->enrollments_count * 10) + ($user->quiz_attempts_count * 5);
            
            if ($score >= 50) {
                $levels['high']++;
            } elseif ($score >= 20) {
                $levels['medium']++;
            } else {
                $levels['low']++;
            }
        }
        
        return $levels;
    }

    /**
     * Get instructor course stats
     */
    private function getInstructorCourseStats($instructor, $startDate)
    {
        return [
            'total_courses' => $instructor->courses()->count(),
            'active_courses' => $instructor->courses()->where('status', 'published')->count(),
            'new_courses' => $instructor->courses()->where('created_at', '>=', $startDate)->count(),
            'total_enrollments' => $instructor->courses()->withCount('enrollments')->get()->sum('enrollments_count'),
            'average_rating' => $instructor->courses()->avg('rating'),
            'completion_rate' => $this->calculateInstructorCompletionRate($instructor, $startDate)
        ];
    }

    /**
     * Get instructor earnings
     */
    private function getInstructorEarnings($instructor, $startDate)
    {
        $earnings = $instructor->earnings()
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, SUM(amount) as daily_earnings')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        
        return [
            'total_earnings' => $earnings->sum('daily_earnings'),
            'average_daily_earnings' => $earnings->avg('daily_earnings'),
            'daily_earnings' => $earnings->pluck('daily_earnings', 'date'),
            'earnings_growth' => $this->calculateInstructorEarningsGrowth($instructor, $startDate)
        ];
    }

    /**
     * Calculate instructor completion rate
     */
    private function calculateInstructorCompletionRate($instructor, $startDate)
    {
        $totalEnrollments = $instructor->courses()
            ->join('course_progress', 'courses.id', '=', 'course_progress.course_id')
            ->where('course_progress.created_at', '>=', $startDate)
            ->count();
        
        $completedEnrollments = $instructor->courses()
            ->join('course_progress', 'courses.id', '=', 'course_progress.course_id')
            ->where('course_progress.created_at', '>=', $startDate)
            ->where('course_progress.completion_rate', 100)
            ->count();
        
        return $totalEnrollments > 0 ? round(($completedEnrollments / $totalEnrollments) * 100, 2) : 0;
    }

    /**
     * Calculate instructor earnings growth
     */
    private function calculateInstructorEarningsGrowth($instructor, $startDate)
    {
        $currentPeriod = $instructor->earnings()->where('created_at', '>=', $startDate)->sum('amount');
        $previousPeriod = $instructor->earnings()
            ->whereBetween('created_at', [
                $startDate->copy()->subDays($startDate->diffInDays(now())),
                $startDate
            ])
            ->sum('amount');
        
        if ($previousPeriod == 0) {
            return $currentPeriod > 0 ? 100 : 0;
        }
        
        return round((($currentPeriod - $previousPeriod) / $previousPeriod) * 100, 2);
    }
} 