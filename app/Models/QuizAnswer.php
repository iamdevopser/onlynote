<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_attempt_id',
        'quiz_question_id',
        'user_answer',
        'is_correct',
        'points_earned',
        'feedback',
        'answered_at'
    ];

    protected $casts = [
        'user_answer' => 'array',
        'is_correct' => 'boolean',
        'answered_at' => 'datetime',
    ];

    /**
     * Get the quiz attempt that owns the answer.
     */
    public function attempt()
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    /**
     * Get the question that owns the answer.
     */
    public function question()
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }

    /**
     * Get formatted user answer for display.
     */
    public function getFormattedUserAnswerAttribute()
    {
        if (empty($this->user_answer)) {
            return 'No answer provided';
        }

        switch ($this->question->type) {
            case 'multiple_choice':
            case 'single_choice':
                $options = $this->question->options;
                $answers = [];
                foreach ($this->user_answer as $key) {
                    $answers[] = $options[$key] ?? $key;
                }
                return implode(', ', $answers);
            
            case 'true_false':
                return $this->user_answer[0] ?? 'No answer';
            
            case 'fill_blank':
                return $this->user_answer[0] ?? 'No answer';
            
            case 'essay':
                return $this->user_answer[0] ?? 'No answer';
            
            default:
                return is_array($this->user_answer) ? implode(', ', $this->user_answer) : $this->user_answer;
        }
    }

    /**
     * Get correct answer for display.
     */
    public function getCorrectAnswerDisplayAttribute()
    {
        if (empty($this->question->correct_answers)) {
            return 'No correct answer defined';
        }

        switch ($this->question->type) {
            case 'multiple_choice':
            case 'single_choice':
                $options = $this->question->options;
                $answers = [];
                foreach ($this->question->correct_answers as $key) {
                    $answers[] = $options[$key] ?? $key;
                }
                return implode(', ', $answers);
            
            case 'true_false':
                return $this->question->correct_answers[0];
            
            case 'fill_blank':
                return implode(', ', $this->question->correct_answers);
            
            case 'essay':
                return 'Manual grading required';
            
            default:
                return implode(', ', $this->question->correct_answers);
        }
    }

    /**
     * Check if answer is correct.
     */
    public function checkAnswer()
    {
        if (empty($this->user_answer)) {
            $this->is_correct = false;
            $this->points_earned = 0;
            return;
        }

        $isCorrect = $this->question->isCorrectAnswer($this->user_answer[0] ?? $this->user_answer);
        
        $this->is_correct = $isCorrect;
        $this->points_earned = $isCorrect ? $this->question->points : 0;
        $this->answered_at = now();
        
        $this->save();
    }

    /**
     * Get answer status for display.
     */
    public function getStatusAttribute()
    {
        if ($this->is_correct === null) {
            return 'pending';
        }

        return $this->is_correct ? 'correct' : 'incorrect';
    }

    /**
     * Get status color for display.
     */
    public function getStatusColorAttribute()
    {
        switch ($this->status) {
            case 'correct':
                return 'success';
            case 'incorrect':
                return 'danger';
            case 'pending':
                return 'warning';
            default:
                return 'secondary';
        }
    }

    /**
     * Scope to get correct answers.
     */
    public function scopeCorrect($query)
    {
        return $query->where('is_correct', true);
    }

    /**
     * Scope to get incorrect answers.
     */
    public function scopeIncorrect($query)
    {
        return $query->where('is_correct', false);
    }

    /**
     * Scope to get pending answers.
     */
    public function scopePending($query)
    {
        return $query->whereNull('is_correct');
    }

    /**
     * Scope to get answers for a specific question.
     */
    public function scopeForQuestion($query, $questionId)
    {
        return $query->where('quiz_question_id', $questionId);
    }

    /**
     * Scope to get answers for a specific attempt.
     */
    public function scopeForAttempt($query, $attemptId)
    {
        return $query->where('quiz_attempt_id', $attemptId);
    }
} 