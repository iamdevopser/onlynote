<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Leaderboard extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type', // global, course, weekly, monthly, yearly
        'course_id',
        'start_date',
        'end_date',
        'is_active',
        'sort_by', // points, badges, courses_completed, etc.
        'sort_order', // asc, desc
        'max_entries',
        'metadata'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
        'max_entries' => 'integer',
        'metadata' => 'array'
    ];

    /**
     * Get the course that owns the leaderboard.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the entries for this leaderboard.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LeaderboardEntry::class);
    }

    /**
     * Check if leaderboard is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if leaderboard is time-based.
     */
    public function isTimeBased(): bool
    {
        return !is_null($this->start_date) && !is_null($this->end_date);
    }

    /**
     * Check if leaderboard is currently running.
     */
    public function isCurrentlyRunning(): bool
    {
        if (!$this->isTimeBased()) {
            return true;
        }

        $now = now();
        return $now->isBetween($this->start_date, $this->end_date);
    }

    /**
     * Check if leaderboard has ended.
     */
    public function hasEnded(): bool
    {
        if (!$this->isTimeBased()) {
            return false;
        }

        return now()->isAfter($this->end_date);
    }

    /**
     * Get formatted date range.
     */
    public function getFormattedDateRangeAttribute(): string
    {
        if (!$this->isTimeBased()) {
            return 'Ongoing';
        }

        $start = $this->start_date->format('M d, Y');
        $end = $this->end_date->format('M d, Y');

        return $start . ' - ' . $end;
    }

    /**
     * Get time remaining.
     */
    public function getTimeRemainingAttribute(): ?string
    {
        if (!$this->isTimeBased() || $this->hasEnded()) {
            return null;
        }

        $now = now();
        $end = $this->end_date;

        $diff = $now->diff($end);
        
        if ($diff->days > 0) {
            return $diff->days . ' days remaining';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hours remaining';
        } else {
            return $diff->i . ' minutes remaining';
        }
    }

    /**
     * Get leaderboard type badge.
     */
    public function getTypeBadgeAttribute(): string
    {
        return match($this->type) {
            'global' => 'bg-primary',
            'course' => 'bg-success',
            'weekly' => 'bg-info',
            'monthly' => 'bg-warning',
            'yearly' => 'bg-danger',
            default => 'bg-secondary'
        };
    }

    /**
     * Scope to get active leaderboards.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get global leaderboards.
     */
    public function scopeGlobal($query)
    {
        return $query->where('type', 'global');
    }

    /**
     * Scope to get course leaderboards.
     */
    public function scopeByCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    /**
     * Scope to get time-based leaderboards.
     */
    public function scopeTimeBased($query)
    {
        return $query->whereNotNull('start_date')->whereNotNull('end_date');
    }

    /**
     * Scope to get currently running leaderboards.
     */
    public function scopeCurrentlyRunning($query)
    {
        return $query->where('start_date', '<=', now())->where('end_date', '>=', now());
    }
} 