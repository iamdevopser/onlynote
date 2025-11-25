<?php

namespace App\Services;

use App\Models\User;
use App\Models\Course;
use App\Models\QuizAttempt;
use App\Models\CourseProgress;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AILearningAssistantService
{
    protected $openaiApiKey;
    protected $openaiEndpoint = 'https://api.openai.com/v1/chat/completions';
    
    public function __construct()
    {
        $this->openaiApiKey = config('services.openai.api_key');
    }

    /**
     * Get personalized learning recommendations
     */
    public function getLearningRecommendations(User $user, $limit = 5)
    {
        $cacheKey = "ai_recommendations_{$user->id}";
        
        return Cache::remember($cacheKey, 3600, function () use ($user, $limit) {
            try {
                $userProfile = $this->analyzeUserProfile($user);
                $learningHistory = $this->getLearningHistory($user);
                $strengths = $this->identifyStrengths($user);
                $weaknesses = $this->identifyWeaknesses($user);
                
                $prompt = $this->buildRecommendationPrompt($userProfile, $learningHistory, $strengths, $weaknesses);
                
                $response = $this->callOpenAI($prompt);
                
                return $this->parseRecommendations($response, $limit);
            } catch (\Exception $e) {
                Log::error('AI Learning Assistant Error: ' . $e->getMessage());
                return $this->getFallbackRecommendations($user, $limit);
            }
        });
    }

    /**
     * Get personalized study plan
     */
    public function getStudyPlan(User $user, Course $course)
    {
        $cacheKey = "ai_study_plan_{$user->id}_{$course->id}";
        
        return Cache::remember($cacheKey, 7200, function () use ($user, $course) {
            try {
                $courseContent = $this->analyzeCourseContent($course);
                $userProgress = $this->getUserProgress($user, $course);
                $learningStyle = $this->getLearningStyle($user);
                
                $prompt = $this->buildStudyPlanPrompt($courseContent, $userProgress, $learningStyle);
                
                $response = $this->callOpenAI($prompt);
                
                return $this->parseStudyPlan($response);
            } catch (\Exception $e) {
                Log::error('AI Study Plan Error: ' . $e->getMessage());
                return $this->getFallbackStudyPlan($course);
            }
        });
    }

    /**
     * Get quiz explanations and hints
     */
    public function getQuizHelp(User $user, QuizAttempt $attempt, $questionIndex)
    {
        $cacheKey = "ai_quiz_help_{$attempt->id}_{$questionIndex}";
        
        return Cache::remember($cacheKey, 1800, function () use ($user, $attempt, $questionIndex) {
            try {
                $question = $attempt->quiz->questions[$questionIndex] ?? null;
                if (!$question) {
                    return null;
                }
                
                $userAnswer = $attempt->answers[$questionIndex] ?? null;
                $correctAnswer = $question->correct_answer;
                
                $prompt = $this->buildQuizHelpPrompt($question, $userAnswer, $correctAnswer);
                
                $response = $this->callOpenAI($prompt);
                
                return $this->parseQuizHelp($response);
            } catch (\Exception $e) {
                Log::error('AI Quiz Help Error: ' . $e->getMessage());
                return $this->getFallbackQuizHelp($question);
            }
        });
    }

    /**
     * Get learning path suggestions
     */
    public function getLearningPath(User $user, $goal)
    {
        $cacheKey = "ai_learning_path_{$user->id}_{$goal}";
        
        return Cache::remember($cacheKey, 3600, function () use ($user, $goal) {
            try {
                $currentSkills = $this->assessCurrentSkills($user);
                $targetSkills = $this->analyzeGoalRequirements($goal);
                $availableCourses = $this->getAvailableCourses();
                
                $prompt = $this->buildLearningPathPrompt($currentSkills, $targetSkills, $availableCourses);
                
                $response = $this->callOpenAI($prompt);
                
                return $this->parseLearningPath($response);
            } catch (\Exception $e) {
                Log::error('AI Learning Path Error: ' . $e->getMessage());
                return $this->getFallbackLearningPath($goal);
            }
        });
    }

    /**
     * Get personalized feedback
     */
    public function getPersonalizedFeedback(User $user, $context)
    {
        try {
            $userProfile = $this->analyzeUserProfile($user);
            $contextData = $this->analyzeContext($context);
            
            $prompt = $this->buildFeedbackPrompt($userProfile, $contextData);
            
            $response = $this->callOpenAI($prompt);
            
            return $this->parseFeedback($response);
        } catch (\Exception $e) {
            Log::error('AI Feedback Error: ' . $e->getMessage());
            return $this->getFallbackFeedback($context);
        }
    }

    /**
     * Analyze user profile
     */
    private function analyzeUserProfile(User $user)
    {
        $profile = [
            'role' => $user->role,
            'experience_level' => $this->calculateExperienceLevel($user),
            'learning_preferences' => $this->getLearningPreferences($user),
            'completed_courses' => $user->enrollments()->where('status', 'completed')->count(),
            'total_study_time' => $user->enrollments()->sum('learning_hours'),
            'average_quiz_score' => $this->calculateAverageQuizScore($user),
            'favorite_categories' => $this->getFavoriteCategories($user),
            'learning_streak' => $this->calculateLearningStreak($user)
        ];
        
        return $profile;
    }

    /**
     * Get learning history
     */
    private function getLearningHistory(User $user)
    {
        return $user->enrollments()
            ->with(['course', 'progress'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($enrollment) {
                return [
                    'course_title' => $enrollment->course->title,
                    'category' => $enrollment->course->category->name,
                    'completion_rate' => $enrollment->progress->completion_rate ?? 0,
                    'quiz_scores' => $enrollment->quizAttempts->pluck('score')->toArray(),
                    'study_time' => $enrollment->learning_hours,
                    'completed_at' => $enrollment->completed_at
                ];
            });
    }

    /**
     * Identify user strengths
     */
    private function identifyStrengths(User $user)
    {
        $strengths = [];
        
        // Analyze quiz performance
        $quizScores = $user->quizAttempts()
            ->with('quiz')
            ->get()
            ->groupBy('quiz.category_id');
        
        foreach ($quizScores as $categoryId => $attempts) {
            $averageScore = $attempts->avg('score');
            if ($averageScore >= 80) {
                $category = \App\Models\Category::find($categoryId);
                $strengths[] = [
                    'category' => $category->name,
                    'average_score' => round($averageScore, 1),
                    'attempts' => $attempts->count()
                ];
            }
        }
        
        return $strengths;
    }

    /**
     * Identify user weaknesses
     */
    private function identifyWeaknesses(User $user)
    {
        $weaknesses = [];
        
        // Analyze quiz performance
        $quizScores = $user->quizAttempts()
            ->with('quiz')
            ->get()
            ->groupBy('quiz.category_id');
        
        foreach ($quizScores as $categoryId => $attempts) {
            $averageScore = $attempts->avg('score');
            if ($averageScore < 60) {
                $category = \App\Models\Category::find($categoryId);
                $weaknesses[] = [
                    'category' => $category->name,
                    'average_score' => round($averageScore, 1),
                    'attempts' => $attempts->count(),
                    'recommended_review' => true
                ];
            }
        }
        
        return $weaknesses;
    }

    /**
     * Call OpenAI API
     */
    private function callOpenAI($prompt)
    {
        if (!$this->openaiApiKey) {
            throw new \Exception('OpenAI API key not configured');
        }
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->openaiApiKey,
            'Content-Type' => 'application/json'
        ])->post($this->openaiEndpoint, [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an AI learning assistant for an LMS platform. Provide helpful, personalized learning recommendations and study plans.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 1000,
            'temperature' => 0.7
        ]);
        
        if ($response->successful()) {
            return $response->json();
        }
        
        throw new \Exception('OpenAI API call failed: ' . $response->body());
    }

    /**
     * Build recommendation prompt
     */
    private function buildRecommendationPrompt($userProfile, $learningHistory, $strengths, $weaknesses)
    {
        return "Based on the following user profile, provide personalized learning recommendations:\n\n" .
               "User Profile: " . json_encode($userProfile) . "\n\n" .
               "Learning History: " . json_encode($learningHistory) . "\n\n" .
               "Strengths: " . json_encode($strengths) . "\n\n" .
               "Weaknesses: " . json_encode($weaknesses) . "\n\n" .
               "Please provide 5 specific, actionable learning recommendations in JSON format with fields: title, description, reason, difficulty, estimated_time, and category.";
    }

    /**
     * Parse AI recommendations
     */
    private function parseRecommendations($response, $limit)
    {
        try {
            $content = $response['choices'][0]['message']['content'] ?? '';
            $recommendations = json_decode($content, true);
            
            if (is_array($recommendations)) {
                return array_slice($recommendations, 0, $limit);
            }
            
            return [];
        } catch (\Exception $e) {
            Log::error('Failed to parse AI recommendations: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get fallback recommendations
     */
    private function getFallbackRecommendations(User $user, $limit)
    {
        $categories = \App\Models\Category::inRandomOrder()->limit($limit)->get();
        
        return $categories->map(function ($category) {
            return [
                'title' => "Explore {$category->name}",
                'description' => "Discover courses in {$category->name} category",
                'reason' => 'Based on popular categories',
                'difficulty' => 'beginner',
                'estimated_time' => '2-4 hours',
                'category' => $category->name
            ];
        })->toArray();
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
     * Get learning preferences
     */
    private function getLearningPreferences(User $user)
    {
        $preferences = [];
        
        // Analyze preferred study times
        $studyTimes = $user->enrollments()
            ->whereNotNull('last_accessed_at')
            ->get()
            ->map(function ($enrollment) {
                return \Carbon\Carbon::parse($enrollment->last_accessed_at)->hour;
            });
        
        if ($studyTimes->count() > 0) {
            $avgHour = $studyTimes->avg();
            if ($avgHour < 12) {
                $preferences[] = 'morning_learner';
            } elseif ($avgHour < 18) {
                $preferences[] = 'afternoon_learner';
            } else {
                $preferences[] = 'evening_learner';
            }
        }
        
        // Analyze preferred content types
        $videoCourses = $user->enrollments()->whereHas('course', function ($query) {
            $query->where('has_video', true);
        })->count();
        
        $textCourses = $user->enrollments()->whereHas('course', function ($query) {
            $query->where('has_video', false);
        })->count();
        
        if ($videoCourses > $textCourses) {
            $preferences[] = 'visual_learner';
        } else {
            $preferences[] = 'text_learner';
        }
        
        return $preferences;
    }

    /**
     * Calculate average quiz score
     */
    private function calculateAverageQuizScore(User $user)
    {
        return $user->quizAttempts()->avg('score') ?? 0;
    }

    /**
     * Get favorite categories
     */
    private function getFavoriteCategories(User $user)
    {
        return $user->enrollments()
            ->with('course.category')
            ->get()
            ->groupBy('course.category_id')
            ->map(function ($enrollments, $categoryId) {
                $category = $enrollments->first()->course->category;
                return [
                    'id' => $categoryId,
                    'name' => $category->name,
                    'enrollment_count' => $enrollments->count()
                ];
            })
            ->sortByDesc('enrollment_count')
            ->take(5)
            ->values();
    }

    /**
     * Calculate learning streak
     */
    private function calculateLearningStreak(User $user)
    {
        $enrollments = $user->enrollments()
            ->whereNotNull('last_accessed_at')
            ->orderBy('last_accessed_at', 'desc')
            ->get();
        
        if ($enrollments->isEmpty()) {
            return 0;
        }
        
        $streak = 0;
        $currentDate = now()->startOfDay();
        
        foreach ($enrollments as $enrollment) {
            $lastAccess = \Carbon\Carbon::parse($enrollment->last_accessed_at)->startOfDay();
            $diff = $currentDate->diffInDays($lastAccess);
            
            if ($diff <= 1) {
                $streak++;
                $currentDate = $lastAccess;
            } else {
                break;
            }
        }
        
        return $streak;
    }
} 