<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\AnalyticsService;
use App\Models\Course;
use Symfony\Component\HttpFoundation\Response;

class TrackAnalytics
{
    protected $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Sadece frontend kurs sayfalarında analytics topla
        if ($request->is('course/*') || $request->is('courses/*')) {
            $this->trackCourseView($request);
        }

        return $response;
    }

    /**
     * Kurs ziyaretini kaydet
     */
    protected function trackCourseView(Request $request)
    {
        try {
            // URL'den course ID'yi çıkar
            $courseId = $this->extractCourseId($request->path());
            
            if ($courseId) {
                $course = Course::find($courseId);
                
                if ($course && $course->instructor_id) {
                    // Analytics verilerini kaydet
                    $this->analyticsService->recordCourseView($courseId, $course->instructor_id);
                    
                    // Unique visitor sayısını artır (session bazlı)
                    $this->trackUniqueVisitor($courseId, $course->instructor_id);
                }
            }
        } catch (\Exception $e) {
            // Hata durumunda log kaydet ama uygulamayı durdurma
            \Log::error('Analytics tracking error: ' . $e->getMessage());
        }
    }

    /**
     * URL'den course ID'yi çıkar
     */
    protected function extractCourseId($path)
    {
        // Örnek: /course/123 -> 123
        if (preg_match('/\/course\/(\d+)/', $path, $matches)) {
            return $matches[1];
        }
        
        // Örnek: /courses/123/details -> 123
        if (preg_match('/\/courses\/(\d+)/', $path, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Unique visitor sayısını artır
     */
    protected function trackUniqueVisitor($courseId, $instructorId)
    {
        $sessionKey = "course_view_{$courseId}";
        
        if (!session()->has($sessionKey)) {
            // İlk ziyaret, unique visitor sayısını artır
            $today = \Carbon\Carbon::today();
            
            $analytics = \App\Models\CourseAnalytics::firstOrCreate([
                'course_id' => $courseId,
                'instructor_id' => $instructorId,
                'date' => $today
            ]);
            
            $analytics->increment('unique_visitors');
            
            // Session'a kaydet (24 saat geçerli)
            session([$sessionKey => true]);
            session()->put($sessionKey, true);
        }
    }
} 