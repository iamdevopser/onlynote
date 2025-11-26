<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutoEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'evaluation_type', // quiz, assignment, course_progress, overall
        'evaluation_date',
        'score',
        'max_score',
        'percentage',
        'grade', // A, B, C, D, F
        'feedback',
        'recommendations',
        'strengths',
        'weaknesses',
        'improvement_areas',
        'next_steps',
        'is_automated',
        'metadata'
    ];

    protected $casts = [
        'evaluation_date' => 'datetime',
        'is_automated' => 'boolean',
        'metadata' => 'array'
    ];

    /**
     * Get the user that owns the evaluation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the course that owns the evaluation.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the evaluation criteria for this evaluation.
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(AutoEvaluationCriterion::class);
    }

    /**
     * Check if evaluation is automated.
     */
    public function isAutomated(): bool
    {
        return $this->is_automated;
    }

    /**
     * Get grade badge class.
     */
    public function getGradeBadgeAttribute(): string
    {
        return match($this->grade) {
            'A' => 'bg-success',
            'B' => 'bg-info',
            'C' => 'bg-warning',
            'D' => 'bg-orange',
            'F' => 'bg-danger',
            default => 'bg-secondary'
        };
    }

    /**
     * Get grade description.
     */
    public function getGradeDescriptionAttribute(): string
    {
        return match($this->grade) {
            'A' => 'Excellent',
            'B' => 'Good',
            'C' => 'Average',
            'D' => 'Below Average',
            'F' => 'Failing',
            default => 'Not Graded'
        };
    }

    /**
     * Check if grade is passing.
     */
    public function isPassing(): bool
    {
        return in_array($this->grade, ['A', 'B', 'C']);
    }

    /**
     * Get formatted score.
     */
    public function getFormattedScoreAttribute(): string
    {
        return $this->score . '/' . $this->max_score;
    }

    /**
     * Get formatted percentage.
     */
    public function getFormattedPercentageAttribute(): string
    {
        return $this->percentage . '%';
    }

    /**
     * Get evaluation type badge.
     */
    public function getTypeBadgeAttribute(): string
    {
        return match($this->evaluation_type) {
            'quiz' => 'bg-primary',
            'assignment' => 'bg-info',
            'course_progress' => 'bg-success',
            'overall' => 'bg-warning',
            default => 'bg-secondary'
        };
    }

    /**
     * Get evaluation type text.
     */
    public function getTypeTextAttribute(): string
    {
        return ucfirst(str_replace('_', ' ', $this->evaluation_type));
    }

    /**
     * Scope to get evaluations by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get evaluations by course.
     */
    public function scopeByCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    /**
     * Scope to get evaluations by type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('evaluation_type', $type);
    }

    /**
     * Scope to get automated evaluations.
     */
    public function scopeAutomated($query)
    {
        return $query->where('is_automated', true);
    }

    /**
     * Scope to get passing evaluations.
     */
    public function scopePassing($query)
    {
        return $query->whereIn('grade', ['A', 'B', 'C']);
    }

    /**
     * Scope to get failing evaluations.
     */
    public function scopeFailing($query)
    {
        return $query->whereIn('grade', ['D', 'F']);
    }

    /**
     * Scope to get recent evaluations.
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('evaluation_date', 'desc');
    }
}










