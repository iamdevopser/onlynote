<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizAnswer;
use App\Models\User;
use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class QuizService
{
    /**
     * Create a new quiz
     */
    public function createQuiz(array $data, int $instructorId): Quiz
    {
        // Verify instructor owns the course
        $course = Course::where('id', $data['course_id'])
            ->where('instructor_id', $instructorId)
            ->firstOrFail();

        return Quiz::create([
            'course_id' => $data['course_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'time_limit' => $data['time_limit'] ?? null,
            'passing_score' => $data['passing_score'],
            'max_attempts' => $data['max_attempts'],
            'shuffle_questions' => $data['shuffle_questions'] ?? false,
            'show_correct_answers' => $data['show_correct_answers'] ?? true,
            'show_results_immediately' => $data['show_results_immediately'] ?? true,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * Update an existing quiz
     */
    public function updateQuiz(Quiz $quiz, array $data, int $instructorId): bool
    {
        // Verify instructor owns the course
        $course = Course::where('id', $data['course_id'])
            ->where('instructor_id', $instructorId)
            ->firstOrFail();

        return $quiz->update($data);
    }

    /**
     * Delete a quiz
     */
    public function deleteQuiz(Quiz $quiz): bool
    {
        // Check if quiz has attempts
        if ($quiz->attempts()->count() > 0) {
            throw new \Exception('Cannot delete quiz that has attempts. Deactivate it instead.');
        }

        return $quiz->delete();
    }

    /**
     * Start a new quiz attempt
     */
    public function startQuizAttempt(Quiz $quiz, int $userId): QuizAttempt
    {
        // Check if quiz is available for user
        if (!$quiz->isAvailableForUser($userId)) {
            throw new \Exception('Quiz is not available for this user.');
        }

        // Get next attempt number
        $attemptNumber = $quiz->userAttempts($userId)->count() + 1;

        return QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $userId,
            'attempt_number' => $attemptNumber,
            'started_at' => now(),
            'status' => 'in_progress',
        ]);
    }

    /**
     * Submit quiz answers and complete attempt
     */
    public function submitQuizAttempt(QuizAttempt $attempt, array $answers): QuizAttempt
    {
        DB::beginTransaction();
        try {
            // Process answers
            foreach ($answers as $questionId => $answer) {
                $question = $attempt->quiz->activeQuestions()->find($questionId);
                if ($question) {
                    QuizAnswer::create([
                        'quiz_attempt_id' => $attempt->id,
                        'quiz_question_id' => $questionId,
                        'user_answer' => is_array($answer) ? $answer : [$answer],
                        'answered_at' => now(),
                    ]);
                }
            }

            // Complete the attempt
            $attempt->complete();

            DB::commit();
            return $attempt;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Resume an in-progress attempt
     */
    public function resumeQuizAttempt(QuizAttempt $attempt): QuizAttempt
    {
        // Check if attempt is still valid
        if ($attempt->quiz->time_limit) {
            $timeElapsed = $attempt->started_at->diffInMinutes(now());
            if ($timeElapsed >= $attempt->quiz->time_limit) {
                // Auto-complete the attempt
                $attempt->abandon();
                throw new \Exception('Time limit exceeded. Your attempt has been submitted.');
            }
        }

        return $attempt;
    }

    /**
     * Abandon a quiz attempt
     */
    public function abandonQuizAttempt(QuizAttempt $attempt): bool
    {
        return $attempt->abandon();
    }

    /**
     * Get quiz statistics
     */
    public function getQuizStatistics(Quiz $quiz): array
    {
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
     * Get user quiz progress
     */
    public function getUserQuizProgress(int $userId): array
    {
        $attempts = QuizAttempt::where('user_id', $userId)
            ->with(['quiz.course'])
            ->where('status', 'completed')
            ->get();

        $totalQuizzes = $attempts->unique('quiz_id')->count();
        $passedQuizzes = $attempts->where('passed', true)->unique('quiz_id')->count();
        $averageScore = $attempts->avg('percentage') ?? 0;
        $totalAttempts = $attempts->count();

        return [
            'total_quizzes_taken' => $totalQuizzes,
            'passed_quizzes' => $passedQuizzes,
            'failed_quizzes' => $totalQuizzes - $passedQuizzes,
            'average_score' => round($averageScore, 2),
            'total_attempts' => $totalAttempts,
            'success_rate' => $totalQuizzes > 0 ? round(($passedQuizzes / $totalQuizzes) * 100, 2) : 0,
        ];
    }

    /**
     * Get course quiz progress
     */
    public function getCourseQuizProgress(Course $course, int $userId): array
    {
        $quizzes = $course->quizzes()->with(['attempts' => function ($query) use ($userId) {
            $query->where('user_id', $userId);
        }])->get();

        $progress = [];
        foreach ($quizzes as $quiz) {
            $bestAttempt = $quiz->bestAttempt($userId);
            $progress[] = [
                'quiz' => $quiz,
                'best_attempt' => $bestAttempt,
                'attempt_count' => $quiz->userAttempts($userId)->count(),
                'is_available' => $quiz->isAvailableForUser($userId),
                'is_completed' => $bestAttempt && $bestAttempt->status === 'completed',
                'is_passed' => $bestAttempt && $bestAttempt->passed,
            ];
        }

        return $progress;
    }

    /**
     * Create quiz questions in bulk
     */
    public function createBulkQuestions(Quiz $quiz, array $questions): array
    {
        $createdQuestions = [];
        
        DB::beginTransaction();
        try {
            foreach ($questions as $index => $questionData) {
                $question = QuizQuestion::create([
                    'quiz_id' => $quiz->id,
                    'question' => $questionData['question'],
                    'type' => $questionData['type'],
                    'options' => $questionData['options'] ?? null,
                    'correct_answers' => $questionData['correct_answers'] ?? null,
                    'explanation' => $questionData['explanation'] ?? null,
                    'points' => $questionData['points'] ?? 1,
                    'order' => $questionData['order'] ?? $index + 1,
                    'is_active' => true,
                ]);
                
                $createdQuestions[] = $question;
            }
            
            DB::commit();
            return $createdQuestions;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reorder quiz questions
     */
    public function reorderQuestions(Quiz $quiz, array $questionOrders): bool
    {
        DB::beginTransaction();
        try {
            foreach ($questionOrders as $questionData) {
                $question = $quiz->questions()->find($questionData['id']);
                if ($question) {
                    $question->update(['order' => $questionData['order']]);
                }
            }
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Export quiz results
     */
    public function exportQuizResults(Quiz $quiz, string $format = 'json'): array
    {
        $attempts = $quiz->attempts()
            ->with(['user', 'answers.question'])
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();

        $results = [];
        foreach ($attempts as $attempt) {
            $results[] = [
                'user_id' => $attempt->user_id,
                'user_name' => $attempt->user->name,
                'user_email' => $attempt->user->email,
                'attempt_number' => $attempt->attempt_number,
                'started_at' => $attempt->started_at->format('Y-m-d H:i:s'),
                'completed_at' => $attempt->completed_at->format('Y-m-d H:i:s'),
                'time_taken' => $attempt->formatted_time_taken,
                'score' => $attempt->score,
                'total_points' => $attempt->total_points,
                'percentage' => $attempt->percentage,
                'passed' => $attempt->passed ? 'Yes' : 'No',
                'grade' => $attempt->grade_letter,
            ];
        }

        return $results;
    }

    /**
     * Get quiz analytics
     */
    public function getQuizAnalytics(Quiz $quiz): array
    {
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
                'question_text' => Str::limit($question->question, 50),
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
} 