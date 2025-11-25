<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'type',
        'time_limit',
        'passing_score',
        'max_attempts',
        'shuffle_questions',
        'show_correct_answers',
        'show_results_immediately',
        'is_active',
        'start_date',
        'end_date'
    ];

    protected $casts = [
        'shuffle_questions' => 'boolean',
        'show_correct_answers' => 'boolean',
        'show_results_immediately' => 'boolean',
        'is_active' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    /**
     * Get the course that owns the quiz.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the questions for the quiz.
     */
    public function questions()
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('order');
    }

    /**
     * Get the active questions for the quiz.
     */
    public function activeQuestions()
    {
        return $this->hasMany(QuizQuestion::class)->where('is_active', true)->orderBy('order');
    }

    /**
     * Get the attempts for the quiz.
     */
    public function attempts()
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Get the attempts for a specific user.
     */
    public function userAttempts($userId)
    {
        return $this->hasMany(QuizAttempt::class)->where('user_id', $userId);
    }

    /**
     * Get the best attempt for a specific user.
     */
    public function bestAttempt($userId)
    {
        return $this->userAttempts($userId)
            ->where('status', 'completed')
            ->orderBy('percentage', 'desc')
            ->first();
    }

    /**
     * Check if quiz is available for a user.
     */
    public function isAvailableForUser($userId)
    {
        if (!$this->is_active) {
            return false;
        }

        // Check date restrictions
        $now = now();
        if ($this->start_date && $now < $this->start_date) {
            return false;
        }
        if ($this->end_date && $now > $this->end_date) {
            return false;
        }

        // Check attempt limits
        $attemptCount = $this->userAttempts($userId)->count();
        if ($attemptCount >= $this->max_attempts) {
            return false;
        }

        return true;
    }

    /**
     * Get total points for the quiz.
     */
    public function getTotalPointsAttribute()
    {
        return $this->activeQuestions()->sum('points');
    }

    /**
     * Get question count for the quiz.
     */
    public function getQuestionCountAttribute()
    {
        return $this->activeQuestions()->count();
    }

    /**
     * Get average score for the quiz.
     */
    public function getAverageScoreAttribute()
    {
        $completedAttempts = $this->attempts()->where('status', 'completed');
        if ($completedAttempts->count() === 0) {
            return 0;
        }

        return $completedAttempts->avg('percentage');
    }

    /**
     * Get pass rate for the quiz.
     */
    public function getPassRateAttribute()
    {
        $completedAttempts = $this->attempts()->where('status', 'completed');
        if ($completedAttempts->count() === 0) {
            return 0;
        }

        $passedAttempts = $completedAttempts->where('passed', true)->count();
        return ($passedAttempts / $completedAttempts->count()) * 100;
    }

    /**
     * Scope to get active quizzes.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get quizzes by type.
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get quizzes for a course.
     */
    public function scopeForCourse($query, $courseId)
    {
        return $query->where('course_id', $courseId);
    }
} 