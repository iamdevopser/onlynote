<?php

namespace App\Http\Controllers\frontend;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAnswer;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class StudentQuizController extends Controller
{
    /**
     * Display a listing of available quizzes for student's enrolled courses.
     */
    public function index()
    {
        $userId = Auth::id();
        
        // Get quizzes from courses where user is enrolled
        $quizzes = Quiz::with(['course', 'attempts' => function ($query) use ($userId) {
            $query->where('user_id', $userId);
        }])
        ->whereHas('course.enrollments', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->where('is_active', true)
        ->orderBy('created_at', 'desc')
        ->paginate(10);

        return view('frontend.quiz.index', compact('quizzes'));
    }

    /**
     * Display the specified quiz details.
     */
    public function show($id)
    {
        $userId = Auth::id();
        
        $quiz = Quiz::with(['course', 'questions'])
            ->whereHas('course.enrollments', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->where('is_active', true)
            ->findOrFail($id);

        // Check if quiz is available for user
        if (!$quiz->isAvailableForUser($userId)) {
            return redirect()->route('student.quizzes.index')
                ->with('error', 'This quiz is not available for you.');
        }

        // Get user's attempts for this quiz
        $attempts = $quiz->userAttempts($userId)->orderBy('created_at', 'desc')->get();
        $bestAttempt = $quiz->bestAttempt($userId);

        return view('frontend.quiz.show', compact('quiz', 'attempts', 'bestAttempt'));
    }

    /**
     * Start a new quiz attempt.
     */
    public function start($id)
    {
        $userId = Auth::id();
        
        $quiz = Quiz::with(['course', 'activeQuestions'])
            ->whereHas('course.enrollments', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->where('is_active', true)
            ->findOrFail($id);

        // Check if quiz is available for user
        if (!$quiz->isAvailableForUser($userId)) {
            return redirect()->route('student.quizzes.show', $id)
                ->with('error', 'This quiz is not available for you.');
        }

        // Get next attempt number
        $attemptNumber = $quiz->userAttempts($userId)->count() + 1;

        // Create new attempt
        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $userId,
            'attempt_number' => $attemptNumber,
            'started_at' => now(),
            'status' => 'in_progress',
        ]);

        // Shuffle questions if enabled
        $questions = $quiz->activeQuestions;
        if ($quiz->shuffle_questions) {
            $questions = $questions->shuffle();
        }

        return view('frontend.quiz.take', compact('quiz', 'attempt', 'questions'));
    }

    /**
     * Submit quiz answers.
     */
    public function submit(Request $request, $attemptId)
    {
        $userId = Auth::id();
        
        $attempt = QuizAttempt::with(['quiz.course', 'quiz.activeQuestions'])
            ->where('user_id', $userId)
            ->where('status', 'in_progress')
            ->findOrFail($attemptId);

        // Check if user is enrolled in the course
        if (!$attempt->quiz->course->enrollments()->where('user_id', $userId)->exists()) {
            return redirect()->route('student.quizzes.index')
                ->with('error', 'Access denied.');
        }

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            'answers.*' => 'nullable',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Process answers
        foreach ($request->answers as $questionId => $answer) {
            $question = $attempt->quiz->activeQuestions->find($questionId);
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

        return redirect()->route('student.quizzes.result', $attempt->id);
    }

    /**
     * Display quiz result.
     */
    public function result($attemptId)
    {
        $userId = Auth::id();
        
        $attempt = QuizAttempt::with(['quiz.course', 'quiz.activeQuestions', 'answers.question'])
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->findOrFail($attemptId);

        return view('frontend.quiz.result', compact('attempt'));
    }

    /**
     * Display quiz attempt history.
     */
    public function history()
    {
        $userId = Auth::id();
        
        $attempts = QuizAttempt::with(['quiz.course'])
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('frontend.quiz.history', compact('attempts'));
    }

    /**
     * Display detailed attempt review.
     */
    public function review($attemptId)
    {
        $userId = Auth::id();
        
        $attempt = QuizAttempt::with(['quiz.course', 'quiz.activeQuestions', 'answers.question'])
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->findOrFail($attemptId);

        // Check if quiz shows correct answers
        if (!$attempt->quiz->show_correct_answers) {
            return redirect()->route('student.quizzes.result', $attempt->id)
                ->with('error', 'Correct answers are not available for this quiz.');
        }

        return view('frontend.quiz.review', compact('attempt'));
    }

    /**
     * Resume an in-progress attempt.
     */
    public function resume($attemptId)
    {
        $userId = Auth::id();
        
        $attempt = QuizAttempt::with(['quiz.course', 'quiz.activeQuestions'])
            ->where('user_id', $userId)
            ->where('status', 'in_progress')
            ->findOrFail($attemptId);

        // Check if attempt is still valid (within time limit)
        if ($attempt->quiz->time_limit) {
            $timeElapsed = $attempt->started_at->diffInMinutes(now());
            if ($timeElapsed >= $attempt->quiz->time_limit) {
                // Auto-complete the attempt
                $attempt->abandon();
                return redirect()->route('student.quizzes.result', $attempt->id)
                    ->with('error', 'Time limit exceeded. Your attempt has been submitted.');
            }
        }

        $questions = $attempt->quiz->activeQuestions;
        if ($attempt->quiz->shuffle_questions) {
            $questions = $questions->shuffle();
        }

        return view('frontend.quiz.take', compact('attempt', 'questions'));
    }

    /**
     * Abandon current attempt.
     */
    public function abandon($attemptId)
    {
        $userId = Auth::id();
        
        $attempt = QuizAttempt::with('quiz.course')
            ->where('user_id', $userId)
            ->where('status', 'in_progress')
            ->findOrFail($attemptId);

        $attempt->abandon();

        return redirect()->route('student.quizzes.show', $attempt->quiz->id)
            ->with('success', 'Quiz attempt abandoned successfully.');
    }

    /**
     * Get quiz progress for a course.
     */
    public function courseProgress($courseId)
    {
        $userId = Auth::id();
        
        $course = Course::with(['quizzes.attempts' => function ($query) use ($userId) {
            $query->where('user_id', $userId);
        }])
        ->whereHas('enrollments', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->findOrFail($courseId);

        $progress = [];
        foreach ($course->quizzes as $quiz) {
            $bestAttempt = $quiz->bestAttempt($userId);
            $progress[] = [
                'quiz' => $quiz,
                'best_attempt' => $bestAttempt,
                'attempt_count' => $quiz->userAttempts($userId)->count(),
                'is_available' => $quiz->isAvailableForUser($userId),
            ];
        }

        return view('frontend.quiz.course-progress', compact('course', 'progress'));
    }

    /**
     * Get quiz statistics for student.
     */
    public function statistics()
    {
        $userId = Auth::id();
        
        $statistics = [
            'total_attempts' => QuizAttempt::where('user_id', $userId)->count(),
            'completed_attempts' => QuizAttempt::where('user_id', $userId)
                ->where('status', 'completed')->count(),
            'passed_attempts' => QuizAttempt::where('user_id', $userId)
                ->where('passed', true)->count(),
            'average_score' => QuizAttempt::where('user_id', $userId)
                ->where('status', 'completed')->avg('percentage') ?? 0,
            'total_quizzes_taken' => QuizAttempt::where('user_id', $userId)
                ->distinct('quiz_id')->count(),
        ];

        return view('frontend.quiz.statistics', compact('statistics'));
    }
} 