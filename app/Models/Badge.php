<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Badge extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'icon',
        'type', // achievement, milestone, special, event
        'rarity', // common, rare, epic, legendary
        'points',
        'criteria_type', // course_completion, quiz_score, assignment_count, etc.
        'criteria_value',
        'criteria_operator', // >=, <=, ==, >, <
        'is_active',
        'is_hidden',
        'unlock_message',
        'metadata'
    ];

    protected $casts = [
        'points' => 'integer',
        'criteria_value' => 'integer',
        'is_active' => 'boolean',
        'is_hidden' => 'boolean',
        'metadata' => 'array'
    ];

    /**
     * Get the users who have earned this badge.
     */
    public function users(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    /**
     * Check if badge is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if badge is hidden.
     */
    public function isHidden(): bool
    {
        return $this->is_hidden;
    }

    /**
     * Get rarity badge class.
     */
    public function getRarityBadgeAttribute(): string
    {
        return match($this->rarity) {
            'common' => 'bg-secondary',
            'rare' => 'bg-primary',
            'epic' => 'bg-purple',
            'legendary' => 'bg-warning',
            default => 'bg-secondary'
        };
    }

    /**
     * Get rarity text.
     */
    public function getRarityTextAttribute(): string
    {
        return ucfirst($this->rarity);
    }

    /**
     * Get type badge class.
     */
    public function getTypeBadgeAttribute(): string
    {
        return match($this->type) {
            'achievement' => 'bg-success',
            'milestone' => 'bg-info',
            'special' => 'bg-warning',
            'event' => 'bg-danger',
            default => 'bg-secondary'
        };
    }

    /**
     * Get formatted points.
     */
    public function getFormattedPointsAttribute(): string
    {
        return $this->points . ' points';
    }

    /**
     * Check if user meets criteria for this badge.
     */
    public function checkCriteria(User $user): bool
    {
        switch ($this->criteria_type) {
            case 'course_completion':
                $value = $user->enrollments()->where('status', 'completed')->count();
                break;
            case 'quiz_score':
                $value = $user->quizAttempts()->avg('percentage') ?? 0;
                break;
            case 'assignment_count':
                $value = $user->assignmentSubmissions()->count();
                break;
            case 'discussion_posts':
                $value = $user->discussionReplies()->count();
                break;
            case 'live_class_attendance':
                $value = $user->liveClassParticipants()->count();
                break;
            case 'streak_days':
                $value = $user->getCurrentStreak();
                break;
            case 'total_points':
                $value = $user->getTotalPoints();
                break;
            default:
                return false;
        }

        return $this->evaluateCriteria($value, $this->criteria_operator, $this->criteria_value);
    }

    /**
     * Evaluate criteria based on operator.
     */
    private function evaluateCriteria($value, string $operator, $targetValue): bool
    {
        return match($operator) {
            '>=' => $value >= $targetValue,
            '<=' => $value <= $targetValue,
            '==' => $value == $targetValue,
            '>' => $value > $targetValue,
            '<' => $value < $targetValue,
            default => false
        };
    }

    /**
     * Scope to get active badges.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get visible badges.
     */
    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Scope to get badges by type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get badges by rarity.
     */
    public function scopeByRarity($query, string $rarity)
    {
        return $query->where('rarity', $rarity);
    }

    /**
     * Scope to get badges by criteria type.
     */
    public function scopeByCriteriaType($query, string $criteriaType)
    {
        return $query->where('criteria_type', $criteriaType);
    }
} 