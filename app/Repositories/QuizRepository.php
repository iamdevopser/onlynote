<?php

namespace App\Repositories;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizAnswer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class QuizRepository
{
    protected $quiz;
    protected $quizAttempt;
    protected $quizQuestion;
    protected $quizAnswer;

    public function __construct(
        Quiz $quiz,
        QuizAttempt $quizAttempt,
        QuizQuestion $quizQuestion,
        QuizAnswer $quizAnswer
    ) {
        $this->quiz = $quiz;
        $this->quizAttempt = $quizAttempt;
        $this->quizQuestion = $quizQuestion;
        $this->quizAnswer = $quizAnswer;
    }

    /**
     * Get all quizzes with pagination
     */
    public function getAllQuizzes(int $perPage = 10): LengthAwarePaginator
    {
        return $this->quiz
            ->with(['course', 'questions'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get quizzes by instructor
     */
    public function getQuizzesByInstructor(int $instructorId, int $perPage = 10): LengthAwarePaginator
    {
        return $this->quiz
            ->with(['course', 'questions'])
            ->whereHas('course', function ($query) use ($instructorId) {
                $query->where('instructor_id', $instructorId);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get quizzes by course
     */
    public function getQuizzesByCourse(int $courseId, bool $activeOnly = true): Collection
    {
        $query = $this->quiz
            ->with(['questions', 'attempts'])
            ->where('course_id', $courseId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->orderBy('order')->get();
    }

    /**
     * Get available quizzes for user
     */
    public function getAvailableQuizzesForUser(int $userId, int $perPage = 10): LengthAwarePaginator
    {
        return $this->quiz
            ->with(['course', 'attempts' => function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])
            ->whereHas('course.enrollments', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Find quiz by ID
     */
    public function findQuizById(int $id): ?Quiz
    {
        return $this->quiz
            ->with(['course', 'questions', 'attempts'])
            ->find($id);
    }

    /**
     * Find quiz by ID with instructor check
     */
    public function findQuizByIdForInstructor(int $id, int $instructorId): ?Quiz
    {
        return $this->quiz
            ->with(['course', 'questions', 'attempts'])
            ->whereHas('course', function ($query) use ($instructorId) {
                $query->where('instructor_id', $instructorId);
            })
            ->find($id);
    }

    /**
     * Find quiz by ID with enrollment check
     */
    public function findQuizByIdForUser(int $id, int $userId): ?Quiz
    {
        return $this->quiz
            ->with(['course', 'questions'])
            ->whereHas('course.enrollments', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->where('is_active', true)
            ->find($id);
    }

    /**
     * Create new quiz
     */
    public function createQuiz(array $data): Quiz
    {
        return $this->quiz->create($data);
    }

    /**
     * Update quiz
     */
    public function updateQuiz(Quiz $quiz, array $data): bool
    {
        return $quiz->update($data);
    }

    /**
     * Delete quiz
     */
    public function deleteQuiz(Quiz $quiz): bool
    {
        return $quiz->delete();
    }

    /**
     * Toggle quiz status
     */
    public function toggleQuizStatus(Quiz $quiz): bool
    {
        return $quiz->update(['is_active' => !$quiz->is_active]);
    }

    /**
     * Get quiz statistics
     */
    public function getQuizStatistics(int $quizId): array
    {
        $quiz = $this->quiz->with(['attempts.user'])->find($quizId);
        
        if (!$quiz) {
            return [];
        }

        $totalAttempts = $quiz->attempts()->count();
        $completedAttempts = $quiz->attempts()->where('status', 'completed')->count();
        $passedAttempts = $quiz->attempts()->where('passed', true)->count();
        $averageScore = $quiz->attempts()->where('status', 'completed')->avg('percentage') ?? 0;
        $passRate = $completedAttempts > 0 ? ($passedAttempts / $completedAttempts) * 100 : 0;

        return [
            'total_attempts' => $totalAttempts,
            'completed_attempts' => $completedAttempts,
            'passed_attempts' => $passedAttempts,
            'failed_attempts' => $completedAttempts - $passedAttempts,
            'average_score' => round($averageScore, 2),
            'pass_rate' => round($passRate, 2),
            'question_count' => $quiz->question_count,
            'total_points' => $quiz->total_points,
        ];
    }

    /**
     * Get quiz attempts
     */
    public function getQuizAttempts(int $quizId, int $perPage = 10): LengthAwarePaginator
    {
        return $this->quizAttempt
            ->with(['user'])
            ->where('quiz_id', $quizId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get user quiz attempts
     */
    public function getUserQuizAttempts(int $userId, int $perPage = 10): LengthAwarePaginator
    {
        return $this->quizAttempt
            ->with(['quiz.course'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get quiz attempt by ID
     */
    public function findQuizAttemptById(int $id): ?QuizAttempt
    {
        return $this->quizAttempt
            ->with(['quiz.course', 'quiz.activeQuestions', 'answers.question'])
            ->find($id);
    }

    /**
     * Get quiz attempt by ID for user
     */
    public function findQuizAttemptByIdForUser(int $id, int $userId): ?QuizAttempt
    {
        return $this->quizAttempt
            ->with(['quiz.course', 'quiz.activeQuestions', 'answers.question'])
            ->where('user_id', $userId)
            ->find($id);
    }

    /**
     * Create quiz attempt
     */
    public function createQuizAttempt(array $data): QuizAttempt
    {
        return $this->quizAttempt->create($data);
    }

    /**
     * Update quiz attempt
     */
    public function updateQuizAttempt(QuizAttempt $attempt, array $data): bool
    {
        return $attempt->update($data);
    }

    /**
     * Get quiz questions
     */
    public function getQuizQuestions(int $quizId, bool $activeOnly = true): Collection
    {
        $query = $this->quizQuestion->where('quiz_id', $quizId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->orderBy('order')->get();
    }

    /**
     * Find quiz question by ID
     */
    public function findQuizQuestionById(int $id): ?QuizQuestion
    {
        return $this->quizQuestion->find($id);
    }

    /**
     * Create quiz question
     */
    public function createQuizQuestion(array $data): QuizQuestion
    {
        return $this->quizQuestion->create($data);
    }

    /**
     * Update quiz question
     */
    public function updateQuizQuestion(QuizQuestion $question, array $data): bool
    {
        return $question->update($data);
    }

    /**
     * Delete quiz question
     */
    public function deleteQuizQuestion(QuizQuestion $question): bool
    {
        return $question->delete();
    }

    /**
     * Toggle question status
     */
    public function toggleQuestionStatus(QuizQuestion $question): bool
    {
        return $question->update(['is_active' => !$question->is_active]);
    }

    /**
     * Reorder questions
     */
    public function reorderQuestions(int $quizId, array $questionOrders): bool
    {
        foreach ($questionOrders as $questionData) {
            $question = $this->quizQuestion->find($questionData['id']);
            if ($question && $question->quiz_id == $quizId) {
                $question->update(['order' => $questionData['order']]);
            }
        }
        return true;
    }

    /**
     * Get quiz answers for attempt
     */
    public function getQuizAnswersForAttempt(int $attemptId): Collection
    {
        return $this->quizAnswer
            ->with(['question'])
            ->where('quiz_attempt_id', $attemptId)
            ->get();
    }

    /**
     * Create quiz answer
     */
    public function createQuizAnswer(array $data): QuizAnswer
    {
        return $this->quizAnswer->create($data);
    }

    /**
     * Update quiz answer
     */
    public function updateQuizAnswer(QuizAnswer $answer, array $data): bool
    {
        return $answer->update($data);
    }

    /**
     * Get quiz analytics
     */
    public function getQuizAnalytics(int $quizId): array
    {
        $quiz = $this->quiz->with(['attempts', 'activeQuestions'])->find($quizId);
        
        if (!$quiz) {
            return [];
        }

        $attempts = $quiz->attempts()->where('status', 'completed')->get();
        
        if ($attempts->isEmpty()) {
            return [
                'total_attempts' => 0,
                'average_score' => 0,
                'pass_rate' => 0,
                'score_distribution' => [],
                'time_distribution' => [],
                'question_analysis' => [],
            ];
        }

        // Score distribution
        $scoreDistribution = [
            '90-100' => $attempts->whereBetween('percentage', [90, 100])->count(),
            '80-89' => $attempts->whereBetween('percentage', [80, 89.99])->count(),
            '70-79' => $attempts->whereBetween('percentage', [70, 79.99])->count(),
            '60-69' => $attempts->whereBetween('percentage', [60, 69.99])->count(),
            '0-59' => $attempts->where('percentage', '<', 60)->count(),
        ];

        // Time distribution
        $timeDistribution = [
            '0-15 min' => $attempts->where('time_taken', '<=', 900)->count(),
            '15-30 min' => $attempts->whereBetween('time_taken', [901, 1800])->count(),
            '30-60 min' => $attempts->whereBetween('time_taken', [1801, 3600])->count(),
            '60+ min' => $attempts->where('time_taken', '>', 3600)->count(),
        ];

        // Question analysis
        $questionAnalysis = [];
        foreach ($quiz->activeQuestions as $question) {
            $questionAttempts = $attempts->filter(function ($attempt) use ($question) {
                return $attempt->answers->where('quiz_question_id', $question->id)->isNotEmpty();
            });

            $correctAnswers = $questionAttempts->filter(function ($attempt) use ($question) {
                $answer = $attempt->answers->where('quiz_question_id', $question->id)->first();
                return $answer && $answer->is_correct;
            })->count();

            $questionAnalysis[] = [
                'question_id' => $question->id,
                'question_text' => \Str::limit($question->question, 50),
                'type' => $question->type,
                'points' => $question->points,
                'total_attempts' => $questionAttempts->count(),
                'correct_attempts' => $correctAnswers,
                'success_rate' => $questionAttempts->count() > 0 ? 
                    round(($correctAnswers / $questionAttempts->count()) * 100, 2) : 0,
            ];
        }

        return [
            'total_attempts' => $attempts->count(),
            'average_score' => round($attempts->avg('percentage'), 2),
            'pass_rate' => round(($attempts->where('passed', true)->count() / $attempts->count()) * 100, 2),
            'score_distribution' => $scoreDistribution,
            'time_distribution' => $timeDistribution,
            'question_analysis' => $questionAnalysis,
        ];
    }

    /**
     * Search quizzes
     */
    public function searchQuizzes(string $search, int $perPage = 10): LengthAwarePaginator
    {
        return $this->quiz
            ->with(['course', 'questions'])
            ->where('title', 'like', "%{$search}%")
            ->orWhere('description', 'like', "%{$search}%")
            ->orWhereHas('course', function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get quizzes by type
     */
    public function getQuizzesByType(string $type, int $perPage = 10): LengthAwarePaginator
    {
        return $this->quiz
            ->with(['course', 'questions'])
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get recent quizzes
     */
    public function getRecentQuizzes(int $limit = 5): Collection
    {
        return $this->quiz
            ->with(['course'])
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get popular quizzes
     */
    public function getPopularQuizzes(int $limit = 5): Collection
    {
        return $this->quiz
            ->with(['course'])
            ->where('is_active', true)
            ->withCount('attempts')
            ->orderBy('attempts_count', 'desc')
            ->limit($limit)
            ->get();
    }
} 