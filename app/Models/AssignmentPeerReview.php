<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentPeerReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'reviewer_id',
        'score',
        'feedback',
        'criteria_scores',
        'reviewed_at',
        'status'
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'criteria_scores' => 'array'
    ];

    /**
     * Get the submission that owns the review.
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class);
    }

    /**
     * Get the reviewer (user) for this review.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * Check if review is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Get formatted score.
     */
    public function getFormattedScoreAttribute(): string
    {
        return $this->score . ' points';
    }
}










