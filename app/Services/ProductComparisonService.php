<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductComparisonService
{
    protected $cacheDuration = 3600; // 1 hour

    /**
     * Compare multiple courses
     */
    public function compareCourses($courseIds, $userId = null)
    {
        try {
            if (count($courseIds) < 2) {
                return [
                    'success' => false,
                    'message' => 'At least 2 courses are required for comparison'
                ];
            }

            if (count($courseIds) > 5) {
                return [
                    'success' => false,
                    'message' => 'Maximum 5 courses can be compared at once'
                ];
            }

            $courses = Course::with([
                'category',
                'subcategory',
                'instructor',
                'lessons',
                'reviews',
                'enrollments'
            ])->whereIn('id', $courseIds)->get();

            if ($courses->count() !== count($courseIds)) {
                return [
                    'success' => false,
                    'message' => 'Some courses not found'
                ];
            }

            $comparisonData = $this->prepareComparisonData($courses, $userId);
            
            // Cache comparison result
            $cacheKey = "course_comparison_" . md5(implode(',', $courseIds));
            Cache::put($cacheKey, $comparisonData, $this->cacheDuration);

            return [
                'success' => true,
                'comparison' => $comparisonData,
                'cache_key' => $cacheKey
            ];

        } catch (\Exception $e) {
            Log::error("Course comparison failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Course comparison failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Prepare comparison data
     */
    protected function prepareComparisonData($courses, $userId = null)
    {
        $comparisonData = [
            'courses' => [],
            'categories' => [],
            'instructors' => [],
            'pricing' => [],
            'features' => [],
            'ratings' => [],
            'enrollment_stats' => []
        ];

        foreach ($courses as $course) {
            $courseData = $this->extractCourseData($course, $userId);
            $comparisonData['courses'][] = $courseData;
            
            // Collect unique categories
            if ($course->category) {
                $comparisonData['categories'][$course->category->id] = $course->category;
            }
            
            // Collect unique instructors
            if ($course->instructor) {
                $comparisonData['instructors'][$course->instructor->id] = $course->instructor;
            }
        }

        // Add comparison metrics
        $comparisonData['metrics'] = $this->calculateComparisonMetrics($comparisonData['courses']);
        
        // Add recommendations
        $comparisonData['recommendations'] = $this->generateRecommendations($comparisonData['courses'], $userId);

        return $comparisonData;
    }

    /**
     * Extract course data for comparison
     */
    protected function extractCourseData($course, $userId = null)
    {
        $enrollmentCount = $course->enrollments()->count();
        $reviewCount = $course->reviews()->count();
        $averageRating = $course->reviews()->avg('rating') ?? 0;
        $lessonCount = $course->lessons()->count();
        $totalDuration = $course->lessons()->sum('duration');

        // Check if user is enrolled
        $isEnrolled = $userId ? $course->enrollments()->where('user_id', $userId)->exists() : false;
        
        // Check if user has wishlisted
        $isWishlisted = $userId ? $course->wishlists()->where('user_id', $userId)->exists() : false;

        return [
            'id' => $course->id,
            'title' => $course->title,
            'description' => $course->description,
            'short_description' => $course->short_description,
            'image' => $course->image,
            'price' => $course->selling_price,
            'discount_price' => $course->discount_price,
            'original_price' => $course->original_price,
            'currency' => $course->currency ?? 'USD',
            'category' => $course->category,
            'subcategory' => $course->subcategory,
            'instructor' => $course->instructor,
            'level' => $course->level,
            'language' => $course->language,
            'estimated_duration' => $course->estimated_duration,
            'total_lessons' => $lessonCount,
            'total_duration' => $totalDuration,
            'enrollment_count' => $enrollmentCount,
            'review_count' => $reviewCount,
            'average_rating' => round($averageRating, 1),
            'certificate' => $course->certificate,
            'lifetime_access' => $course->lifetime_access,
            'mobile_access' => $course->mobile_access,
            'downloadable' => $course->downloadable,
            'features' => $course->features ?? [],
            'requirements' => $course->requirements ?? [],
            'outcomes' => $course->outcomes ?? [],
            'is_enrolled' => $isEnrolled,
            'is_wishlisted' => $isWishlisted,
            'created_at' => $course->created_at,
            'updated_at' => $course->updated_at
        ];
    }

    /**
     * Calculate comparison metrics
     */
    protected function calculateComparisonMetrics($courses)
    {
        if (empty($courses)) {
            return [];
        }

        $prices = collect($courses)->pluck('price')->filter();
        $ratings = collect($courses)->pluck('average_rating')->filter();
        $durations = collect($courses)->pluck('total_duration')->filter();
        $enrollments = collect($courses)->pluck('enrollment_count')->filter();

        return [
            'price' => [
                'min' => $prices->min(),
                'max' => $prices->max(),
                'average' => round($prices->avg(), 2),
                'range' => $prices->max() - $prices->min()
            ],
            'rating' => [
                'min' => $ratings->min(),
                'max' => $ratings->max(),
                'average' => round($ratings->avg(), 1),
                'range' => $ratings->max() - $ratings->min()
            ],
            'duration' => [
                'min' => $durations->min(),
                'max' => $durations->max(),
                'average' => round($durations->avg()),
                'range' => $durations->max() - $durations->min()
            ],
            'enrollment' => [
                'min' => $enrollments->min(),
                'max' => $enrollments->max(),
                'average' => round($enrollments->avg()),
                'range' => $enrollments->max() - $enrollments->min()
            ]
        ];
    }

    /**
     * Generate recommendations based on comparison
     */
    protected function generateRecommendations($courses, $userId = null)
    {
        $recommendations = [];

        // Best value for money
        $bestValue = collect($courses)->sortBy(function ($course) {
            $price = $course['price'] ?? 0;
            $rating = $course['average_rating'] ?? 0;
            $lessons = $course['total_lessons'] ?? 0;
            
            if ($price <= 0) return 0;
            
            return ($rating * $lessons) / $price;
        })->last();

        if ($bestValue) {
            $recommendations['best_value'] = [
                'course_id' => $bestValue['id'],
                'title' => $bestValue['title'],
                'reason' => 'Best value for money based on rating, lessons, and price'
            ];
        }

        // Most popular
        $mostPopular = collect($courses)->sortByDesc('enrollment_count')->first();
        if ($mostPopular) {
            $recommendations['most_popular'] = [
                'course_id' => $mostPopular['id'],
                'title' => $mostPopular['title'],
                'reason' => 'Highest enrollment count'
            ];
        }

        // Highest rated
        $highestRated = collect($courses)->sortByDesc('average_rating')->first();
        if ($highestRated) {
            $recommendations['highest_rated'] = [
                'course_id' => $highestRated['id'],
                'title' => $highestRated['title'],
                'reason' => 'Highest average rating'
            ];
        }

        // Most comprehensive
        $mostComprehensive = collect($courses)->sortByDesc('total_lessons')->first();
        if ($mostComprehensive) {
            $recommendations['most_comprehensive'] = [
                'course_id' => $mostComprehensive['id'],
                'title' => $mostComprehensive['title'],
                'reason' => 'Most lessons and content'
            ];
        }

        return $recommendations;
    }

    /**
     * Get comparison history for user
     */
    public function getComparisonHistory($userId, $limit = 10)
    {
        try {
            $cacheKey = "comparison_history_{$userId}";
            
            return Cache::remember($cacheKey, $this->cacheDuration, function () use ($userId, $limit) {
                // This would typically come from a database table
                // For now, return empty array
                return [];
            });

        } catch (\Exception $e) {
            Log::error("Failed to get comparison history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Save comparison to user's history
     */
    public function saveComparison($userId, $courseIds, $comparisonData)
    {
        try {
            $cacheKey = "comparison_history_{$userId}";
            $history = Cache::get($cacheKey, []);
            
            $comparisonRecord = [
                'id' => uniqid(),
                'course_ids' => $courseIds,
                'course_count' => count($courseIds),
                'comparison_data' => $comparisonData,
                'created_at' => now()->toISOString()
            ];
            
            // Add to beginning of history
            array_unshift($history, $comparisonRecord);
            
            // Keep only last 20 comparisons
            $history = array_slice($history, 0, 20);
            
            Cache::put($cacheKey, $history, $this->cacheDuration * 24); // 24 hours
            
            return [
                'success' => true,
                'comparison_id' => $comparisonRecord['id']
            ];

        } catch (\Exception $e) {
            Log::error("Failed to save comparison: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to save comparison: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get comparison by ID
     */
    public function getComparisonById($comparisonId, $userId)
    {
        try {
            $cacheKey = "comparison_history_{$userId}";
            $history = Cache::get($cacheKey, []);
            
            $comparison = collect($history)->firstWhere('id', $comparisonId);
            
            if (!$comparison) {
                return [
                    'success' => false,
                    'message' => 'Comparison not found'
                ];
            }

            return [
                'success' => true,
                'comparison' => $comparison
            ];

        } catch (\Exception $e) {
            Log::error("Failed to get comparison: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to get comparison: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete comparison from history
     */
    public function deleteComparison($comparisonId, $userId)
    {
        try {
            $cacheKey = "comparison_history_{$userId}";
            $history = Cache::get($cacheKey, []);
            
            $history = collect($history)->filter(function ($item) use ($comparisonId) {
                return $item['id'] !== $comparisonId;
            })->values()->toArray();
            
            Cache::put($cacheKey, $history, $this->cacheDuration * 24);
            
            return [
                'success' => true,
                'message' => 'Comparison deleted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to delete comparison: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to delete comparison: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get comparison statistics
     */
    public function getComparisonStats($period = 'month')
    {
        try {
            $startDate = $this->getStartDate($period);
            
            // This would typically come from database analytics
            // For now, return mock data
            $stats = [
                'total_comparisons' => rand(100, 1000),
                'unique_users' => rand(50, 500),
                'average_courses_per_comparison' => rand(2, 4),
                'most_compared_courses' => [],
                'comparison_trends' => [],
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => now()
            ];

            return $stats;

        } catch (\Exception $e) {
            Log::error("Failed to get comparison stats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get start date based on period
     */
    protected function getStartDate($period)
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
     * Export comparison to different formats
     */
    public function exportComparison($comparisonData, $format = 'pdf')
    {
        try {
            switch ($format) {
                case 'pdf':
                    return $this->exportToPDF($comparisonData);
                    
                case 'excel':
                    return $this->exportToExcel($comparisonData);
                    
                case 'csv':
                    return $this->exportToCSV($comparisonData);
                    
                default:
                    return [
                        'success' => false,
                        'message' => 'Unsupported export format'
                    ];
            }

        } catch (\Exception $e) {
            Log::error("Export failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Export to PDF
     */
    protected function exportToPDF($comparisonData)
    {
        // This would integrate with PDF generation library
        return [
            'success' => true,
            'message' => 'PDF export requires additional setup',
            'format' => 'pdf'
        ];
    }

    /**
     * Export to Excel
     */
    protected function exportToExcel($comparisonData)
    {
        // This would integrate with Excel generation library
        return [
            'success' => true,
            'message' => 'Excel export requires additional setup',
            'format' => 'excel'
        ];
    }

    /**
     * Export to CSV
     */
    protected function exportToCSV($comparisonData)
    {
        // This would generate CSV content
        return [
            'success' => true,
            'message' => 'CSV export requires additional setup',
            'format' => 'csv'
        ];
    }
} 