<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'user_id',
        'comment',
        'comment_type', // general, feedback, question, answer
        'is_public',
        'parent_id',
        'metadata'
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'metadata' => 'array'
    ];

    /**
     * Get the submission that owns the comment.
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class);
    }

    /**
     * Get the user that owns the comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent comment if this is a reply.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(AssignmentComment::class, 'parent_id');
    }

    /**
     * Get the replies to this comment.
     */
    public function replies()
    {
        return $this->hasMany(AssignmentComment::class, 'parent_id');
    }

    /**
     * Check if comment is public.
     */
    public function isPublic(): bool
    {
        return $this->is_public;
    }

    /**
     * Check if comment is a reply.
     */
    public function isReply(): bool
    {
        return !is_null($this->parent_id);
    }

    /**
     * Get comment type badge.
     */
    public function getCommentTypeBadgeAttribute(): string
    {
        return match($this->comment_type) {
            'general' => 'bg-secondary',
            'feedback' => 'bg-info',
            'question' => 'bg-warning',
            'answer' => 'bg-success',
            default => 'bg-secondary'
        };
    }
}










