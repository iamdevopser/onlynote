<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'user_id',
        'submission_text',
        'submission_files',
        'submission_links',
        'submitted_at',
        'status', // submitted, graded, returned, late
        'score',
        'max_score',
        'percentage',
        'feedback',
        'graded_by',
        'graded_at',
        'is_late',
        'late_penalty',
        'metadata'
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'submission_files' => 'array',
        'submission_links' => 'array',
        'is_late' => 'boolean',
        'metadata' => 'array'
    ];

    /**
     * Get the assignment that owns the submission.
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * Get the user that owns the submission.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the grader (instructor/admin) for this submission.
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /**
     * Get the peer reviews for this submission.
     */
    public function peerReviews(): HasMany
    {
        return $this->hasMany(AssignmentPeerReview::class);
    }

    /**
     * Get the comments for this submission.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(AssignmentComment::class);
    }

    /**
     * Check if submission is graded.
     */
    public function isGraded(): bool
    {
        return $this->status === 'graded';
    }

    /**
     * Check if submission is late.
     */
    public function isLate(): bool
    {
        return $this->is_late;
    }

    /**
     * Check if submission is overdue.
     */
    public function isOverdue(): bool
    {
        if (!$this->assignment->due_date) {
            return false;
        }

        return $this->submitted_at && $this->submitted_at->isAfter($this->assignment->due_date);
    }

    /**
     * Get final score after late penalty.
     */
    public function getFinalScoreAttribute(): float
    {
        if (!$this->score) {
            return 0;
        }

        if ($this->is_late && $this->late_penalty) {
            return max(0, $this->score - $this->late_penalty);
        }

        return $this->score;
    }

    /**
     * Get final percentage after late penalty.
     */
    public function getFinalPercentageAttribute(): float
    {
        if (!$this->max_score || $this->max_score <= 0) {
            return 0;
        }

        $finalScore = $this->final_score;
        return round(($finalScore / $this->max_score) * 100, 2);
    }

    /**
     * Check if submission passed.
     */
    public function passed(): bool
    {
        return $this->final_percentage >= 70; // Default passing score
    }

    /**
     * Get submission status badge.
     */
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'submitted' => 'bg-warning',
            'graded' => 'bg-success',
            'returned' => 'bg-info',
            'late' => 'bg-danger',
            default => 'bg-secondary'
        };
    }

    /**
     * Get submission status text.
     */
    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            'submitted' => 'Submitted',
            'graded' => 'Graded',
            'returned' => 'Returned',
            'late' => 'Late',
            default => 'Unknown'
        };
    }

    /**
     * Scope to get submissions by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get submissions by assignment.
     */
    public function scopeByAssignment($query, int $assignmentId)
    {
        return $query->where('assignment_id', $assignmentId);
    }

    /**
     * Scope to get graded submissions.
     */
    public function scopeGraded($query)
    {
        return $query->where('status', 'graded');
    }

    /**
     * Scope to get ungraded submissions.
     */
    public function scopeUngraded($query)
    {
        return $query->where('status', 'submitted');
    }

    /**
     * Scope to get late submissions.
     */
    public function scopeLate($query)
    {
        return $query->where('is_late', true);
    }

    /**
     * Scope to get submissions by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}

