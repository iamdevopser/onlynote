<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'discussion_id',
        'reply_id',
        'type', // like, dislike, helpful
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];

    /**
     * Get the user that owns the like.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the discussion that owns the like.
     */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /**
     * Get the reply that owns the like.
     */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(DiscussionReply::class);
    }

    /**
     * Check if like is positive.
     */
    public function isPositive(): bool
    {
        return in_array($this->type, ['like', 'helpful']);
    }

    /**
     * Check if like is negative.
     */
    public function isNegative(): bool
    {
        return $this->type === 'dislike';
    }

    /**
     * Check if like is helpful.
     */
    public function isHelpful(): bool
    {
        return $this->type === 'helpful';
    }

    /**
     * Get like type badge.
     */
    public function getTypeBadgeAttribute(): string
    {
        return match($this->type) {
            'like' => 'bg-success',
            'dislike' => 'bg-danger',
            'helpful' => 'bg-info',
            default => 'bg-secondary'
        };
    }

    /**
     * Scope to get likes by type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get positive likes.
     */
    public function scopePositive($query)
    {
        return $query->whereIn('type', ['like', 'helpful']);
    }

    /**
     * Scope to get negative likes.
     */
    public function scopeNegative($query)
    {
        return $query->where('type', 'dislike');
    }
}










