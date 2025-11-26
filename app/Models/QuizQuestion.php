<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'question',
        'type',
        'options',
        'correct_answers',
        'explanation',
        'points',
        'order',
        'is_active'
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answers' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the quiz that owns the question.
     */
    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Get the answers for this question.
     */
    public function answers()
    {
        return $this->hasMany(QuizAnswer::class);
    }

    /**
     * Check if the given answer is correct.
     */
    public function isCorrectAnswer($userAnswer)
    {
        if (empty($this->correct_answers)) {
            return false;
        }

        switch ($this->type) {
            case 'multiple_choice':
            case 'single_choice':
                return in_array($userAnswer, $this->correct_answers);
            
            case 'true_false':
                return $userAnswer === $this->correct_answers[0];
            
            case 'fill_blank':
                return in_array(strtolower(trim($userAnswer)), array_map('strtolower', $this->correct_answers));
            
            case 'essay':
                // Essay questions need manual grading
                return null;
            
            default:
                return false;
        }
    }

    /**
     * Get formatted options for display.
     */
    public function getFormattedOptionsAttribute()
    {
        if (empty($this->options)) {
            return [];
        }

        $formatted = [];
        foreach ($this->options as $key => $option) {
            $formatted[] = [
                'key' => $key,
                'value' => $option,
                'is_correct' => in_array($key, $this->correct_answers ?? [])
            ];
        }

        return $formatted;
    }

    /**
     * Get question type display name.
     */
    public function getTypeDisplayNameAttribute()
    {
        $types = [
            'multiple_choice' => 'Multiple Choice',
            'single_choice' => 'Single Choice',
            'true_false' => 'True/False',
            'fill_blank' => 'Fill in the Blank',
            'essay' => 'Essay'
        ];

        return $types[$this->type] ?? $this->type;
    }

    /**
     * Scope to get active questions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get questions by type.
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get questions for a quiz.
     */
    public function scopeForQuiz($query, $quizId)
    {
        return $query->where('quiz_id', $quizId);
    }

    /**
     * Scope to order questions by order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
} 