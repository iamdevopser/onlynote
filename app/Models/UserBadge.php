<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBadge extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'badge_id',
        'earned_at',
        'progress',
        'metadata'
    ];

    protected $casts = [
        'earned_at' => 'datetime',
        'progress' => 'integer',
        'metadata' => 'array'
    ];

    /**
     * Get the user that owns the badge.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the badge.
     */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    /**
     * Check if badge was earned recently.
     */
    public function isRecentlyEarned(): bool
    {
        return $this->earned_at && $this->earned_at->diffInDays(now()) <= 7;
    }

    /**
     * Get formatted earned date.
     */
    public function getFormattedEarnedDateAttribute(): string
    {
        if (!$this->earned_at) {
            return 'Unknown';
        }

        $now = now();
        $earned = $this->earned_at;

        if ($earned->isToday()) {
            return 'Today';
        } elseif ($earned->isYesterday()) {
            return 'Yesterday';
        } elseif ($earned->diffInDays($now) <= 7) {
            return $earned->diffInDays($now) . ' days ago';
        } else {
            return $earned->format('M d, Y');
        }
    }

    /**
     * Get progress percentage.
     */
    public function getProgressPercentageAttribute(): int
    {
        if (!$this->badge || !$this->badge->criteria_value) {
            return 0;
        }

        return min(round(($this->progress / $this->badge->criteria_value) * 100), 100);
    }

    /**
     * Scope to get recently earned badges.
     */
    public function scopeRecentlyEarned($query)
    {
        return $query->where('earned_at', '>=', now()->subDays(7));
    }

    /**
     * Scope to get badges by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get badges by badge.
     */
    public function scopeByBadge($query, int $badgeId)
    {
        return $query->where('badge_id', $badgeId);
    }
}










