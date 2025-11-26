<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class InstructorQuizController extends Controller
{
    /**
     * Display a listing of quizzes for instructor's courses.
     */
    public function index()
    {
        $instructorId = Auth::id();
        $quizzes = Quiz::with(['course', 'questions'])
            ->whereHas('course', function ($query) use ($instructorId) {
                $query->where('instructor_id', $instructorId);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('backend.instructor.quiz.index', compact('quizzes'));
    }

    /**
     * Show the form for creating a new quiz.
     */
    public function create()
    {
        $instructorId = Auth::id();
        $courses = Course::where('instructor_id', $instructorId)
            ->where('status', 'published')
            ->get();

        return view('backend.instructor.quiz.create', compact('courses'));
    }

    /**
     * Store a newly created quiz in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:quiz,exam,assignment',
            'time_limit' => 'nullable|integer|min:1',
            'passing_score' => 'required|integer|min:0|max:100',
            'max_attempts' => 'required|integer|min:1|max:10',
            'shuffle_questions' => 'boolean',
            'show_correct_answers' => 'boolean',
            'show_results_immediately' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Check if instructor owns the course
        $course = Course::where('id', $request->course_id)
            ->where('instructor_id', Auth::id())
            ->first();

        if (!$course) {
            return redirect()->back()
                ->with('error', 'Course not found or access denied.')
                ->withInput();
        }

        $quiz = Quiz::create([
            'course_id' => $request->course_id,
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type,
            'time_limit' => $request->time_limit,
            'passing_score' => $request->passing_score,
            'max_attempts' => $request->max_attempts,
            'shuffle_questions' => $request->has('shuffle_questions'),
            'show_correct_answers' => $request->has('show_correct_answers'),
            'show_results_immediately' => $request->has('show_results_immediately'),
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_active' => true,
        ]);

        return redirect()->route('instructor.quizzes.show', $quiz->id)
            ->with('success', 'Quiz created successfully. Now add questions to your quiz.');
    }

    /**
     * Display the specified quiz.
     */
    public function show($id)
    {
        $quiz = Quiz::with(['course', 'questions.answers'])
            ->whereHas('course', function ($query) {
                $query->where('instructor_id', Auth::id());
            })
            ->findOrFail($id);

        $attempts = $quiz->attempts()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('backend.instructor.quiz.show', compact('quiz', 'attempts'));
    }

    /**
     * Show the form for editing the specified quiz.
     */
    public function edit($id)
    {
        $quiz = Quiz::with('course')
            ->whereHas('course', function ($query) {
                $query->where('instructor_id', Auth::id());
            })
            ->findOrFail($id);

        $instructorId = Auth::id();
        $courses = Course::where('instructor_id', $instructorId)
            ->where('status', 'published')
            ->get();

        return view('backend.instructor.quiz.edit', compact('quiz', 'courses'));
    }

    /**
     * Update the specified quiz in storage.
     */
    public function update(Request $request, $id)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:quiz,exam,assignment',
            'time_limit' => 'nullable|integer|min:1',
            'passing_score' => 'required|integer|min:0|max:100',
            'max_attempts' => 'required|integer|min:1|max:10',
            'shuffle_questions' => 'boolean',
            'show_correct_answers' => 'boolean',
            'show_results_immediately' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Check if instructor owns the course
        $course = Course::where('id', $request->course_id)
            ->where('instructor_id', Auth::id())
            ->first();

        if (!$course) {
            return redirect()->back()
                ->with('error', 'Course not found or access denied.')
                ->withInput();
        }

        $quiz->update([
            'course_id' => $request->course_id,
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type,
            'time_limit' => $request->time_limit,
            'passing_score' => $request->passing_score,
            'max_attempts' => $request->max_attempts,
            'shuffle_questions' => $request->has('shuffle_questions'),
            'show_correct_answers' => $request->has('show_correct_answers'),
            'show_results_immediately' => $request->has('show_results_immediately'),
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('instructor.quizzes.show', $quiz->id)
            ->with('success', 'Quiz updated successfully.');
    }

    /**
     * Remove the specified quiz from storage.
     */
    public function destroy($id)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($id);

        // Check if quiz has attempts
        if ($quiz->attempts()->count() > 0) {
            return redirect()->back()
                ->with('error', 'Cannot delete quiz that has attempts. Deactivate it instead.');
        }

        $quiz->delete();

        return redirect()->route('instructor.quizzes.index')
            ->with('success', 'Quiz deleted successfully.');
    }

    /**
     * Toggle quiz active status.
     */
    public function toggleStatus($id)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($id);

        $quiz->update(['is_active' => !$quiz->is_active]);

        $status = $quiz->is_active ? 'activated' : 'deactivated';
        return redirect()->back()
            ->with('success', "Quiz {$status} successfully.");
    }

    /**
     * Get quiz statistics.
     */
    public function statistics($id)
    {
        $quiz = Quiz::with(['course', 'attempts.user'])
            ->whereHas('course', function ($query) {
                $query->where('instructor_id', Auth::id());
            })
            ->findOrFail($id);

        $statistics = [
            'total_attempts' => $quiz->attempts()->count(),
            'completed_attempts' => $quiz->attempts()->where('status', 'completed')->count(),
            'passed_attempts' => $quiz->attempts()->where('passed', true)->count(),
            'average_score' => $quiz->attempts()->where('status', 'completed')->avg('percentage') ?? 0,
            'pass_rate' => $quiz->pass_rate,
            'question_count' => $quiz->question_count,
            'total_points' => $quiz->total_points,
        ];

        return view('backend.instructor.quiz.statistics', compact('quiz', 'statistics'));
    }

    /**
     * Export quiz results.
     */
    public function exportResults($id)
    {
        $quiz = Quiz::with(['course', 'attempts.user'])
            ->whereHas('course', function ($query) {
                $query->where('instructor_id', Auth::id());
            })
            ->findOrFail($id);

        $attempts = $quiz->attempts()
            ->with('user')
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();

        // TODO: Implement CSV/Excel export
        return response()->json($attempts);
    }
} 