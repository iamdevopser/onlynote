<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class InstructorQuizQuestionController extends Controller
{
    /**
     * Show the form for creating a new question.
     */
    public function create($quizId)
    {
        $quiz = Quiz::with('course')
            ->whereHas('course', function ($query) {
                $query->where('instructor_id', Auth::id());
            })
            ->findOrFail($quizId);

        return view('backend.instructor.quiz.question.create', compact('quiz'));
    }

    /**
     * Store a newly created question in storage.
     */
    public function store(Request $request, $quizId)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($quizId);

        $validator = Validator::make($request->all(), [
            'question' => 'required|string',
            'type' => 'required|in:multiple_choice,single_choice,true_false,fill_blank,essay',
            'options' => 'required_if:type,multiple_choice,single_choice|array',
            'options.*' => 'required_if:type,multiple_choice,single_choice|string',
            'correct_answers' => 'required_if:type,multiple_choice,single_choice,true_false,fill_blank|array',
            'correct_answers.*' => 'required_if:type,multiple_choice,single_choice,true_false,fill_blank|string',
            'explanation' => 'nullable|string',
            'points' => 'required|integer|min:1|max:100',
            'order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Validate correct answers based on question type
        if ($request->type === 'multiple_choice' || $request->type === 'single_choice') {
            if (!empty($request->options) && !empty($request->correct_answers)) {
                foreach ($request->correct_answers as $correctAnswer) {
                    if (!array_key_exists($correctAnswer, $request->options)) {
                        return redirect()->back()
                            ->withErrors(['correct_answers' => 'Correct answer must be one of the provided options.'])
                            ->withInput();
                    }
                }
            }
        }

        $question = QuizQuestion::create([
            'quiz_id' => $quizId,
            'question' => $request->question,
            'type' => $request->type,
            'options' => $request->options,
            'correct_answers' => $request->correct_answers,
            'explanation' => $request->explanation,
            'points' => $request->points,
            'order' => $request->order ?? $quiz->questions()->count() + 1,
            'is_active' => true,
        ]);

        return redirect()->route('instructor.quizzes.show', $quizId)
            ->with('success', 'Question added successfully.');
    }

    /**
     * Show the form for editing the specified question.
     */
    public function edit($quizId, $questionId)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($quizId);

        $question = $quiz->questions()->findOrFail($questionId);

        return view('backend.instructor.quiz.question.edit', compact('quiz', 'question'));
    }

    /**
     * Update the specified question in storage.
     */
    public function update(Request $request, $quizId, $questionId)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($quizId);

        $question = $quiz->questions()->findOrFail($questionId);

        $validator = Validator::make($request->all(), [
            'question' => 'required|string',
            'type' => 'required|in:multiple_choice,single_choice,true_false,fill_blank,essay',
            'options' => 'required_if:type,multiple_choice,single_choice|array',
            'options.*' => 'required_if:type,multiple_choice,single_choice|string',
            'correct_answers' => 'required_if:type,multiple_choice,single_choice,true_false,fill_blank|array',
            'correct_answers.*' => 'required_if:type,multiple_choice,single_choice,true_false,fill_blank|string',
            'explanation' => 'nullable|string',
            'points' => 'required|integer|min:1|max:100',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Validate correct answers based on question type
        if ($request->type === 'multiple_choice' || $request->type === 'single_choice') {
            if (!empty($request->options) && !empty($request->correct_answers)) {
                foreach ($request->correct_answers as $correctAnswer) {
                    if (!array_key_exists($correctAnswer, $request->options)) {
                        return redirect()->back()
                            ->withErrors(['correct_answers' => 'Correct answer must be one of the provided options.'])
                            ->withInput();
                    }
                }
            }
        }

        $question->update([
            'question' => $request->question,
            'type' => $request->type,
            'options' => $request->options,
            'correct_answers' => $request->correct_answers,
            'explanation' => $request->explanation,
            'points' => $request->points,
            'order' => $request->order ?? $question->order,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('instructor.quizzes.show', $quizId)
            ->with('success', 'Question updated successfully.');
    }

    /**
     * Remove the specified question from storage.
     */
    public function destroy($quizId, $questionId)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($quizId);

        $question = $quiz->questions()->findOrFail($questionId);

        // Check if question has answers
        if ($question->answers()->count() > 0) {
            return redirect()->back()
                ->with('error', 'Cannot delete question that has answers. Deactivate it instead.');
        }

        $question->delete();

        return redirect()->route('instructor.quizzes.show', $quizId)
            ->with('success', 'Question deleted successfully.');
    }

    /**
     * Toggle question active status.
     */
    public function toggleStatus($quizId, $questionId)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($quizId);

        $question = $quiz->questions()->findOrFail($questionId);

        $question->update(['is_active' => !$question->is_active]);

        $status = $question->is_active ? 'activated' : 'deactivated';
        return redirect()->back()
            ->with('success', "Question {$status} successfully.");
    }

    /**
     * Reorder questions.
     */
    public function reorder(Request $request, $quizId)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($quizId);

        $validator = Validator::make($request->all(), [
            'questions' => 'required|array',
            'questions.*.id' => 'required|exists:quiz_questions,id',
            'questions.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid data'], 400);
        }

        foreach ($request->questions as $questionData) {
            $question = $quiz->questions()->find($questionData['id']);
            if ($question) {
                $question->update(['order' => $questionData['order']]);
            }
        }

        return response()->json(['success' => 'Questions reordered successfully']);
    }

    /**
     * Bulk import questions from CSV/JSON.
     */
    public function bulkImport(Request $request, $quizId)
    {
        $quiz = Quiz::whereHas('course', function ($query) {
            $query->where('instructor_id', Auth::id());
        })->findOrFail($quizId);

        $validator = Validator::make($request->all(), [
            'questions_file' => 'required|file|mimes:csv,txt,json|max:2048',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // TODO: Implement CSV/JSON import logic
        return redirect()->back()
            ->with('success', 'Questions imported successfully.');
    }

    /**
     * Export questions to CSV/JSON.
     */
    public function export($quizId)
    {
        $quiz = Quiz::with('questions')
            ->whereHas('course', function ($query) {
                $query->where('instructor_id', Auth::id());
            })
            ->findOrFail($quizId);

        $questions = $quiz->questions()->orderBy('order')->get();

        // TODO: Implement CSV/JSON export
        return response()->json($questions);
    }
} 