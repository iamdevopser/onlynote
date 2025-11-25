<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discussion extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'user_id',
        'title',
        'content',
        'type', // general, question, announcement, help
        'status', // open, closed, pinned, locked
        'is_pinned',
        'is_locked',
        'view_count',
        'reply_count',
        'last_reply_at',
        'last_reply_by',
        'tags',
        'metadata'
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_locked' => 'boolean',
        'last_reply_at' => 'datetime',
        'tags' => 'array',
        'metadata' => 'array'
    ];

    /**
     * Get the course that owns the discussion.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the user that owns the discussion.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the last replier.
     */
    public function lastReplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reply_by');
    }

    /**
     * Get the replies for this discussion.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(DiscussionReply::class);
    }

    /**
     * Get the likes for this discussion.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(DiscussionLike::class);
    }

    /**
     * Get the tags for this discussion.
     */
    public function getTagsAttribute($value): array
    {
        return json_decode($value, true) ?? [];
    }

    /**
     * Set the tags for this discussion.
     */
    public function setTagsAttribute($value): void
    {
        $this->attributes['tags'] = json_encode($value);
    }

    /**
     * Check if discussion is pinned.
     */
    public function isPinned(): bool
    {
        return $this->is_pinned;
    }

    /**
     * Check if discussion is locked.
     */
    public function isLocked(): bool
    {
        return $this->is_locked;
    }

    /**
     * Check if discussion is open.
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Check if discussion is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Increment view count.
     */
    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    /**
     * Update reply count.
     */
    public function updateReplyCount(): void
    {
        $this->reply_count = $this->replies()->count();
        $this->last_reply_at = $this->replies()->latest()->first()?->created_at;
        $this->last_reply_by = $this->replies()->latest()->first()?->user_id;
        $this->save();
    }

    /**
     * Get formatted view count.
     */
    public function getFormattedViewCountAttribute(): string
    {
        if ($this->view_count >= 1000000) {
            return round($this->view_count / 1000000, 1) . 'M';
        } elseif ($this->view_count >= 1000) {
            return round($this->view_count / 1000, 1) . 'K';
        }
        return $this->view_count;
    }

    /**
     * Get discussion type badge.
     */
    public function getTypeBadgeAttribute(): string
    {
        return match($this->type) {
            'general' => 'bg-secondary',
            'question' => 'bg-info',
            'announcement' => 'bg-warning',
            'help' => 'bg-success',
            default => 'bg-secondary'
        };
    }

    /**
     * Get status badge.
     */
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'open' => 'bg-success',
            'closed' => 'bg-secondary',
            'pinned' => 'bg-warning',
            'locked' => 'bg-danger',
            default => 'bg-secondary'
        };
    }

    /**
     * Scope to get pinned discussions.
     */
    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    /**
     * Scope to get open discussions.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope to get discussions by type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get discussions by course.
     */
    public function scopeByCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    /**
     * Scope to get discussions by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get popular discussions.
     */
    public function scopePopular($query)
    {
        return $query->orderBy('view_count', 'desc');
    }

    /**
     * Scope to get recent discussions.
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}










