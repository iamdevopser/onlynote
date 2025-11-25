<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizAnswer;
use App\Services\QuizService;
use App\Repositories\QuizRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class QuizApiController extends Controller
{
    protected $quizService;
    protected $quizRepository;

    public function __construct(QuizService $quizService, QuizRepository $quizRepository)
    {
        $this->quizService = $quizService;
        $this->quizRepository = $quizRepository;
    }

    /**
     * Get all available quizzes
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');
        $type = $request->get('type');
        $courseId = $request->get('course_id');

        try {
            if ($search) {
                $quizzes = $this->quizRepository->searchQuizzes($search, $perPage);
            } elseif ($type) {
                $quizzes = $this->quizRepository->getQuizzesByType($type, $perPage);
            } elseif ($courseId) {
                $quizzes = $this->quizRepository->getQuizzesByCourse($courseId);
            } else {
                $quizzes = $this->quizRepository->getAllQuizzes($perPage);
            }

            return response()->json([
                'success' => true,
                'data' => $quizzes,
                'message' => 'Quizzes retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve quizzes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get quiz by ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $quiz = $this->quizRepository->findQuizById($id);
            
            if (!$quiz) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $quiz,
                'message' => 'Quiz retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve quiz',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get quiz statistics
     */
    public function statistics(int $id): JsonResponse
    {
        try {
            $statistics = $this->quizRepository->getQuizStatistics($id);
            
            if (empty($statistics)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $statistics,
                'message' => 'Quiz statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve quiz statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get quiz analytics
     */
    public function analytics(int $id): JsonResponse
    {
        try {
            $analytics = $this->quizRepository->getQuizAnalytics($id);
            
            if (empty($analytics)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $analytics,
                'message' => 'Quiz analytics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve quiz analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start quiz attempt
     */
    public function startQuiz(Request $request, int $quizId): JsonResponse
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

        try {
            $quiz = $this->quizRepository->findQuizById($quizId);
            
            if (!$quiz) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz not found'
                ], 404);
            }

            $attempt = $this->quizService->startQuizAttempt($quiz, $request->user_id);

            return response()->json([
                'success' => true,
                'data' => $attempt,
                'message' => 'Quiz attempt started successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to start quiz attempt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit quiz answers
     */
    public function submitQuiz(Request $request, int $attemptId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            'answers.*' => 'nullable'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $attempt = $this->quizRepository->findQuizAttemptById($attemptId);
            
            if (!$attempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz attempt not found'
                ], 404);
            }

            $completedAttempt = $this->quizService->submitQuizAttempt($attempt, $request->answers);

            return response()->json([
                'success' => true,
                'data' => $completedAttempt,
                'message' => 'Quiz submitted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit quiz',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get quiz attempt result
     */
    public function getResult(int $attemptId): JsonResponse
    {
        try {
            $attempt = $this->quizRepository->findQuizAttemptById($attemptId);
            
            if (!$attempt) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz attempt not found'
                ], 404);
            }

            if ($attempt->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz attempt not completed'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $attempt,
                'message' => 'Quiz result retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve quiz result',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user quiz attempts
     */
    public function getUserAttempts(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'per_page' => 'integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $perPage = $request->get('per_page', 10);
            $attempts = $this->quizRepository->getUserQuizAttempts($request->user_id, $perPage);

            return response()->json([
                'success' => true,
                'data' => $attempts,
                'message' => 'User quiz attempts retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user quiz attempts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user quiz progress
     */
    public function getUserProgress(Request $request): JsonResponse
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

        try {
            $progress = $this->quizService->getUserQuizProgress($request->user_id);

            return response()->json([
                'success' => true,
                'data' => $progress,
                'message' => 'User quiz progress retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user quiz progress',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get course quiz progress
     */
    public function getCourseProgress(Request $request, int $courseId): JsonResponse
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

        try {
            $course = Course::findOrFail($courseId);
            $progress = $this->quizService->getCourseQuizProgress($course, $request->user_id);

            return response()->json([
                'success' => true,
                'data' => $progress,
                'message' => 'Course quiz progress retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve course quiz progress',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export quiz results
     */
    public function exportResults(int $quizId, Request $request): JsonResponse
    {
        try {
            $quiz = $this->quizRepository->findQuizById($quizId);
            
            if (!$quiz) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz not found'
                ], 404);
            }

            $format = $request->get('format', 'json');
            $results = $this->quizService->exportQuizResults($quiz, $format);

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => 'Quiz results exported successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export quiz results',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent quizzes
     */
    public function getRecentQuizzes(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 5);
            $quizzes = $this->quizRepository->getRecentQuizzes($limit);

            return response()->json([
                'success' => true,
                'data' => $quizzes,
                'message' => 'Recent quizzes retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve recent quizzes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get popular quizzes
     */
    public function getPopularQuizzes(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 5);
            $quizzes = $this->quizRepository->getPopularQuizzes($limit);

            return response()->json([
                'success' => true,
                'data' => $quizzes,
                'message' => 'Popular quizzes retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve popular quizzes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get quiz questions
     */
    public function getQuestions(int $quizId): JsonResponse
    {
        try {
            $questions = $this->quizRepository->getQuizQuestions($quizId, true);

            return response()->json([
                'success' => true,
                'data' => $questions,
                'message' => 'Quiz questions retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve quiz questions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 