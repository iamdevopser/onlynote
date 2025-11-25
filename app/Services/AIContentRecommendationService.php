<?php

namespace App\Services;

use App\Models\User;
use App\Models\Course;
use App\Models\Category;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class AIContentRecommendationService
{
    protected $openaiApiKey;
    protected $openaiEndpoint = 'https://api.openai.com/v1/chat/completions';
    protected $recommendationCache = [];
    
    public function __construct()
    {
        $this->openaiApiKey = config('services.openai.api_key');
    }

    /**
     * Get personalized course recommendations
     */
    public function getCourseRecommendations(User $user, $limit = 10)
    {
        $cacheKey = "ai_course_recommendations_{$user->id}";
        
        return Cache::remember($cacheKey, 3600, function () use ($user, $limit) {
            try {
                $userProfile = $this->analyzeUserProfile($user);
                $learningPatterns = $this->analyzeLearningPatterns($user);
                $collaborativeRecommendations = $this->getCollaborativeRecommendations($user);
                $contentBasedRecommendations = $this->getContentBasedRecommendations($user);
                
                $recommendations = $this->combineRecommendations([
                    'collaborative' => $collaborativeRecommendations,
                    'content_based' => $contentBasedRecommendations,
                    'user_profile' => $userProfile,
                    'learning_patterns' => $learningPatterns
                ]);
                
                return $this->rankRecommendations($recommendations, $user)->take($limit);
                
            } catch (\Exception $e) {
                Log::error('AI Course Recommendation Error: ' . $e->getMessage());
                return $this->getFallbackRecommendations($user, $limit);
            }
        });
    }

    /**
     * Get next lesson recommendations
     */
    public function getNextLessonRecommendations(User $user, $courseId)
    {
        $cacheKey = "ai_next_lesson_{$user->id}_{$courseId}";
        
        return Cache::remember($cacheKey, 1800, function () use ($user, $courseId) {
            try {
                $enrollment = $user->enrollments()->where('course_id', $courseId)->first();
                
                if (!$enrollment) {
                    return [];
                }
                
                $course = $enrollment->course;
                $completedLessons = $this->getCompletedLessons($user, $courseId);
                $userProgress = $enrollment->progress;
                
                $recommendations = $this->analyzeLessonSequence($course, $completedLessons, $userProgress);
                
                return $recommendations;
                
            } catch (\Exception $e) {
                Log::error('AI Next Lesson Recommendation Error: ' . $e->getMessage());
                return $this->getFallbackNextLessons($user, $courseId);
            }
        });
    }

    /**
     * Get content difficulty recommendations
     */
    public function getDifficultyRecommendations(User $user, $categoryId = null)
    {
        $cacheKey = "ai_difficulty_{$user->id}_{$categoryId}";
        
        return Cache::remember($cacheKey, 7200, function () use ($user, $categoryId) {
            try {
                $userSkillLevel = $this->assessUserSkillLevel($user, $categoryId);
                $availableCourses = $this->getAvailableCourses($categoryId);
                
                $recommendations = $this->matchDifficultyToSkill($userSkillLevel, $availableCourses);
                
                return $recommendations;
                
            } catch (\Exception $e) {
                Log::error('AI Difficulty Recommendation Error: ' . $e->getMessage());
                return $this->getFallbackDifficultyRecommendations($user, $categoryId);
            }
        });
    }

    /**
     * Get learning path recommendations
     */
    public function getLearningPathRecommendations(User $user, $goal)
    {
        $cacheKey = "ai_learning_path_{$user->id}_{$goal}";
        
        return Cache::remember($cacheKey, 3600, function () use ($user, $goal) {
            try {
                $currentSkills = $this->assessCurrentSkills($user);
                $targetSkills = $this->analyzeGoalRequirements($goal);
                $skillGap = $this->calculateSkillGap($currentSkills, $targetSkills);
                
                $learningPath = $this->generateLearningPath($skillGap, $user);
                
                return $learningPath;
                
            } catch (\Exception $e) {
                Log::error('AI Learning Path Error: ' . $e->getMessage());
                return $this->getFallbackLearningPath($goal);
            }
        });
    }

    /**
     * Analyze user profile
     */
    private function analyzeUserProfile(User $user)
    {
        $profile = [
            'demographics' => [
                'age_group' => $this->estimateAgeGroup($user),
                'experience_level' => $this->calculateExperienceLevel($user),
                'learning_style' => $this->identifyLearningStyle($user)
            ],
            'interests' => $this->extractUserInterests($user),
            'goals' => $this->identifyUserGoals($user),
            'constraints' => $this->identifyConstraints($user)
        ];
        
        return $profile;
    }

    /**
     * Analyze learning patterns
     */
    private function analyzeLearningPatterns(User $user)
    {
        $patterns = [
            'study_schedule' => $this->analyzeStudySchedule($user),
            'preferred_content_types' => $this->analyzeContentPreferences($user),
            'engagement_patterns' => $this->analyzeEngagementPatterns($user),
            'completion_rates' => $this->analyzeCompletionRates($user),
            'quiz_performance' => $this->analyzeQuizPerformance($user)
        ];
        
        return $patterns;
    }

    /**
     * Get collaborative recommendations
     */
    private function getCollaborativeRecommendations(User $user)
    {
        // Find similar users based on learning patterns
        $similarUsers = $this->findSimilarUsers($user);
        
        $recommendations = [];
        
        foreach ($similarUsers as $similarUser) {
            $enrolledCourses = $similarUser->enrollments()
                ->where('status', 'completed')
                ->with('course')
                ->get();
            
            foreach ($enrolledCourses as $enrollment) {
                $course = $enrollment->course;
                
                if (!$user->enrollments()->where('course_id', $course->id)->exists()) {
                    $recommendations[] = [
                        'course' => $course,
                        'score' => $this->calculateCollaborativeScore($user, $similarUser, $course),
                        'method' => 'collaborative'
                    ];
                }
            }
        }
        
        return collect($recommendations)->sortByDesc('score');
    }

    /**
     * Get content-based recommendations
     */
    private function getContentBasedRecommendations(User $user)
    {
        $userInterests = $this->extractUserInterests($user);
        $enrolledCategories = $user->enrollments()
            ->with('course.category')
            ->get()
            ->pluck('course.category_id')
            ->unique();
        
        $recommendations = [];
        
        // Find courses in categories user is interested in
        $interestedCourses = Course::whereIn('category_id', $enrolledCategories)
            ->where('status', 'published')
            ->whereNotIn('id', $user->enrollments()->pluck('course_id'))
            ->get();
        
        foreach ($interestedCourses as $course) {
            $score = $this->calculateContentBasedScore($user, $course);
            
            $recommendations[] = [
                'course' => $course,
                'score' => $score,
                'method' => 'content_based'
            ];
        }
        
        return collect($recommendations)->sortByDesc('score');
    }

    /**
     * Find similar users
     */
    private function findSimilarUsers(User $user)
    {
        $userPatterns = $this->getUserLearningPatterns($user);
        
        $similarUsers = User::where('id', '!=', $user->id)
            ->where('role', 'user')
            ->get()
            ->map(function ($otherUser) use ($userPatterns) {
                $otherPatterns = $this->getUserLearningPatterns($otherUser);
                $similarity = $this->calculateUserSimilarity($userPatterns, $otherPatterns);
                
                return [
                    'user' => $otherUser,
                    'similarity' => $similarity
                ];
            })
            ->sortByDesc('similarity')
            ->take(10)
            ->pluck('user');
        
        return $similarUsers;
    }

    /**
     * Get user learning patterns
     */
    private function getUserLearningPatterns(User $user)
    {
        return [
            'preferred_categories' => $user->enrollments()
                ->with('course.category')
                ->get()
                ->groupBy('course.category_id')
                ->map(function ($enrollments) {
                    return $enrollments->count();
                })
                ->sortByDesc(function ($count) {
                    return $count;
                })
                ->take(5)
                ->keys()
                ->toArray(),
            'preferred_difficulty' => $user->enrollments()
                ->with('course')
                ->get()
                ->pluck('course.difficulty_level')
                ->mode(),
            'study_time_preference' => $this->analyzeStudyTimePreference($user),
            'completion_rate' => $user->enrollments()
                ->where('status', 'completed')
                ->count() / max($user->enrollments()->count(), 1)
        ];
    }

    /**
     * Calculate user similarity
     */
    private function calculateUserSimilarity($patterns1, $patterns2)
    {
        $similarity = 0;
        
        // Category preference similarity
        $categorySimilarity = count(array_intersect($patterns1['preferred_categories'], $patterns2['preferred_categories']));
        $similarity += $categorySimilarity * 0.3;
        
        // Difficulty preference similarity
        if ($patterns1['preferred_difficulty'] === $patterns2['preferred_difficulty']) {
            $similarity += 0.2;
        }
        
        // Study time similarity
        $timeDiff = abs($patterns1['study_time_preference'] - $patterns2['study_time_preference']);
        $similarity += max(0, 0.2 - ($timeDiff * 0.1));
        
        // Completion rate similarity
        $completionDiff = abs($patterns1['completion_rate'] - $patterns2['completion_rate']);
        $similarity += max(0, 0.3 - ($completionDiff * 0.3));
        
        return min(1, $similarity);
    }

    /**
     * Calculate collaborative score
     */
    private function calculateCollaborativeScore(User $user, User $similarUser, Course $course)
    {
        $baseScore = 0.5;
        
        // User similarity bonus
        $userPatterns = $this->getUserLearningPatterns($user);
        $similarPatterns = $this->getUserLearningPatterns($similarUser);
        $similarity = $this->calculateUserSimilarity($userPatterns, $similarPatterns);
        
        $baseScore += $similarity * 0.3;
        
        // Course rating bonus
        if ($course->rating > 0) {
            $baseScore += ($course->rating / 5) * 0.2;
        }
        
        // Popularity bonus
        $popularityScore = min(1, $course->enrollment_count / 100);
        $baseScore += $popularityScore * 0.1;
        
        return min(1, $baseScore);
    }

    /**
     * Calculate content-based score
     */
    private function calculateContentBasedScore(User $user, Course $course)
    {
        $score = 0;
        
        // Category preference
        $userCategories = $user->enrollments()
            ->with('course.category')
            ->get()
            ->pluck('course.category_id')
            ->toArray();
        
        if (in_array($course->category_id, $userCategories)) {
            $score += 0.4;
        }
        
        // Difficulty match
        $userPreferredDifficulty = $this->getUserPreferredDifficulty($user);
        if ($course->difficulty_level === $userPreferredDifficulty) {
            $score += 0.3;
        }
        
        // Content type preference
        $userContentPreferences = $this->getUserContentPreferences($user);
        if (in_array($course->content_type, $userContentPreferences)) {
            $score += 0.2;
        }
        
        // Course quality indicators
        if ($course->rating > 4) {
            $score += 0.1;
        }
        
        return min(1, $score);
    }

    /**
     * Combine different recommendation methods
     */
    private function combineRecommendations($recommendations)
    {
        $combined = collect();
        
        // Collaborative recommendations
        foreach ($recommendations['collaborative'] as $rec) {
            $combined->push([
                'course' => $rec['course'],
                'score' => $rec['score'] * 0.4, // 40% weight
                'method' => $rec['method']
            ]);
        }
        
        // Content-based recommendations
        foreach ($recommendations['content_based'] as $rec) {
            $combined->push([
                'course' => $rec['course'],
                'score' => $rec['score'] * 0.3, // 30% weight
                'method' => $rec['method']
            ]);
        }
        
        // User profile recommendations
        foreach ($recommendations['user_profile'] as $rec) {
            $combined->push([
                'course' => $rec['course'],
                'score' => $rec['score'] * 0.2, // 20% weight
                'method' => $rec['method']
            ]);
        }
        
        // Learning pattern recommendations
        foreach ($recommendations['learning_patterns'] as $rec) {
            $combined->push([
                'course' => $rec['course'],
                'score' => $rec['score'] * 0.1, // 10% weight
                'method' => $rec['method']
            ]);
        }
        
        return $combined;
    }

    /**
     * Rank recommendations
     */
    private function rankRecommendations($recommendations, User $user)
    {
        return $recommendations
            ->groupBy('course.id')
            ->map(function ($group) {
                // Combine scores from different methods
                $totalScore = $group->sum('score');
                $methods = $group->pluck('method')->toArray();
                
                return [
                    'course' => $group->first()['course'],
                    'total_score' => $totalScore,
                    'methods' => $methods,
                    'explanation' => $this->generateExplanation($methods, $totalScore)
                ];
            })
            ->sortByDesc('total_score');
    }

    /**
     * Generate recommendation explanation
     */
    private function generateExplanation($methods, $score)
    {
        $explanations = [];
        
        if (in_array('collaborative', $methods)) {
            $explanations[] = 'Benzer kullanıcılar bu kursu beğendi';
        }
        
        if (in_array('content_based', $methods)) {
            $explanations[] = 'İlgi alanlarınıza uygun';
        }
        
        if (in_array('user_profile', $methods)) {
            $explanations[] = 'Profilinize uygun';
        }
        
        if (in_array('learning_patterns', $methods)) {
            $explanations[] = 'Öğrenme alışkanlıklarınıza uygun';
        }
        
        if ($score > 0.8) {
            $explanations[] = 'Yüksek uyum skoru';
        }
        
        return implode(', ', $explanations);
    }

    /**
     * Get fallback recommendations
     */
    private function getFallbackRecommendations(User $user, $limit)
    {
        return Course::where('status', 'published')
            ->orderBy('enrollment_count', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($course) {
                return [
                    'course' => $course,
                    'total_score' => 0.5,
                    'methods' => ['fallback'],
                    'explanation' => 'Popüler kurslar'
                ];
            });
    }

    /**
     * Estimate user age group
     */
    private function estimateAgeGroup(User $user)
    {
        // This would require age field in user model
        // For now, return default
        return 'adult';
    }

    /**
     * Calculate experience level
     */
    private function calculateExperienceLevel(User $user)
    {
        $completedCourses = $user->enrollments()->where('status', 'completed')->count();
        $totalStudyTime = $user->enrollments()->sum('learning_hours');
        
        if ($completedCourses >= 20 && $totalStudyTime >= 100) {
            return 'expert';
        } elseif ($completedCourses >= 10 && $totalStudyTime >= 50) {
            return 'intermediate';
        } elseif ($completedCourses >= 5 && $totalStudyTime >= 20) {
            return 'beginner_plus';
        } else {
            return 'beginner';
        }
    }

    /**
     * Identify learning style
     */
    private function identifyLearningStyle(User $user)
    {
        $videoCourses = $user->enrollments()->whereHas('course', function ($query) {
            $query->where('has_video', true);
        })->count();
        
        $textCourses = $user->enrollments()->whereHas('course', function ($query) {
            $query->where('has_video', false);
        })->count();
        
        if ($videoCourses > $textCourses) {
            return 'visual';
        } elseif ($textCourses > $videoCourses) {
            return 'reading';
        } else {
            return 'balanced';
        }
    }

    /**
     * Extract user interests
     */
    private function extractUserInterests(User $user)
    {
        return $user->enrollments()
            ->with('course.category')
            ->get()
            ->groupBy('course.category.name')
            ->map(function ($enrollments) {
                return $enrollments->count();
            })
            ->sortByDesc(function ($count) {
                return $count;
            })
            ->take(5)
            ->keys()
            ->toArray();
    }

    /**
     * Identify user goals
     */
    private function identifyUserGoals(User $user)
    {
        // This would require user goals field
        // For now, return default goals based on enrollments
        $goals = [];
        
        if ($user->enrollments()->where('status', 'completed')->count() > 0) {
            $goals[] = 'skill_development';
        }
        
        if ($user->enrollments()->where('status', 'in_progress')->count() > 0) {
            $goals[] = 'continuous_learning';
        }
        
        return $goals;
    }

    /**
     * Identify constraints
     */
    private function identifyConstraints(User $user)
    {
        $constraints = [];
        
        // Time constraints based on study patterns
        $avgStudyTime = $user->enrollments()->avg('learning_hours');
        if ($avgStudyTime < 5) {
            $constraints[] = 'time_limited';
        }
        
        // Difficulty constraints based on completion rates
        $completionRate = $user->enrollments()->where('status', 'completed')->count() / 
                         max($user->enrollments()->count(), 1);
        
        if ($completionRate < 0.5) {
            $constraints[] = 'difficulty_sensitive';
        }
        
        return $constraints;
    }

    /**
     * Analyze study schedule
     */
    private function analyzeStudySchedule(User $user)
    {
        $enrollments = $user->enrollments()
            ->whereNotNull('last_accessed_at')
            ->get();
        
        $hourDistribution = $enrollments->map(function ($enrollment) {
            return \Carbon\Carbon::parse($enrollment->last_accessed_at)->hour;
        });
        
        if ($hourDistribution->isEmpty()) {
            return 'unknown';
        }
        
        $avgHour = $hourDistribution->avg();
        
        if ($avgHour < 12) {
            return 'morning';
        } elseif ($avgHour < 18) {
            return 'afternoon';
        } else {
            return 'evening';
        }
    }

    /**
     * Analyze content preferences
     */
    private function analyzeContentPreferences(User $user)
    {
        $preferences = [];
        
        $videoCourses = $user->enrollments()->whereHas('course', function ($query) {
            $query->where('has_video', true);
        })->count();
        
        $textCourses = $user->enrollments()->whereHas('course', function ($query) {
            $query->where('has_video', false);
        })->count();
        
        if ($videoCourses > $textCourses) {
            $preferences[] = 'video';
        } else {
            $preferences[] = 'text';
        }
        
        return $preferences;
    }

    /**
     * Analyze engagement patterns
     */
    private function analyzeEngagementPatterns(User $user)
    {
        $patterns = [];
        
        $enrollments = $user->enrollments()->get();
        
        if ($enrollments->isEmpty()) {
            return $patterns;
        }
        
        $avgProgress = $enrollments->avg('progress');
        
        if ($avgProgress > 80) {
            $patterns[] = 'high_engagement';
        } elseif ($avgProgress > 50) {
            $patterns[] = 'moderate_engagement';
        } else {
            $patterns[] = 'low_engagement';
        }
        
        return $patterns;
    }

    /**
     * Analyze completion rates
     */
    private function analyzeCompletionRates(User $user)
    {
        $totalEnrollments = $user->enrollments()->count();
        $completedEnrollments = $user->enrollments()->where('status', 'completed')->count();
        
        if ($totalEnrollments === 0) {
            return 0;
        }
        
        return ($completedEnrollments / $totalEnrollments) * 100;
    }

    /**
     * Analyze quiz performance
     */
    private function analyzeQuizPerformance(User $user)
    {
        $attempts = $user->quizAttempts();
        
        if ($attempts->count() === 0) {
            return [
                'average_score' => 0,
                'performance_level' => 'unknown'
            ];
        }
        
        $avgScore = $attempts->avg('score');
        
        $performanceLevel = match(true) {
            $avgScore >= 90 => 'excellent',
            $avgScore >= 80 => 'good',
            $avgScore >= 70 => 'average',
            $avgScore >= 60 => 'below_average',
            default => 'needs_improvement'
        };
        
        return [
            'average_score' => $avgScore,
            'performance_level' => $performanceLevel
        ];
    }

    /**
     * Get user preferred difficulty
     */
    private function getUserPreferredDifficulty(User $user)
    {
        $enrollments = $user->enrollments()
            ->with('course')
            ->where('status', 'completed')
            ->get();
        
        if ($enrollments->isEmpty()) {
            return 'beginner';
        }
        
        $difficulties = $enrollments->pluck('course.difficulty_level');
        return $difficulties->mode() ?? 'beginner';
    }

    /**
     * Get user content preferences
     */
    private function getUserContentPreferences(User $user)
    {
        $preferences = [];
        
        $videoCourses = $user->enrollments()->whereHas('course', function ($query) {
            $query->where('has_video', true);
        })->count();
        
        $textCourses = $user->enrollments()->whereHas('course', function ($query) {
            $query->where('has_video', false);
        })->count();
        
        if ($videoCourses > $textCourses) {
            $preferences[] = 'video';
        } else {
            $preferences[] = 'text';
        }
        
        return $preferences;
    }

    /**
     * Analyze study time preference
     */
    private function analyzeStudyTimePreference(User $user)
    {
        $enrollments = $user->enrollments()
            ->whereNotNull('last_accessed_at')
            ->get();
        
        if ($enrollments->isEmpty()) {
            return 12; // Default to noon
        }
        
        $hours = $enrollments->map(function ($enrollment) {
            return \Carbon\Carbon::parse($enrollment->last_accessed_at)->hour;
        });
        
        return round($hours->avg());
    }
} 