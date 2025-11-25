<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'instructor_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'duration', // in minutes
        'max_participants',
        'current_participants',
        'status', // scheduled, live, ended, cancelled
        'meeting_url',
        'meeting_id',
        'meeting_password',
        'recording_url',
        'is_recording_enabled',
        'is_chat_enabled',
        'is_screen_sharing_enabled',
        'is_polling_enabled',
        'is_whiteboard_enabled',
        'metadata'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_recording_enabled' => 'boolean',
        'is_chat_enabled' => 'boolean',
        'is_screen_sharing_enabled' => 'boolean',
        'is_polling_enabled' => 'boolean',
        'is_whiteboard_enabled' => 'boolean',
        'metadata' => 'array'
    ];

    /**
     * Get the course that owns the live class.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the instructor for this live class.
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * Get the participants for this live class.
     */
    public function participants(): HasMany
    {
        return $this->hasMany(LiveClassParticipant::class);
    }

    /**
     * Get the chat messages for this live class.
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(LiveClassChatMessage::class);
    }

    /**
     * Get the polls for this live class.
     */
    public function polls(): HasMany
    {
        return $this->hasMany(LiveClassPoll::class);
    }

    /**
     * Get the whiteboard sessions for this live class.
     */
    public function whiteboardSessions(): HasMany
    {
        return $this->hasMany(LiveClassWhiteboardSession::class);
    }

    /**
     * Check if live class is scheduled.
     */
    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    /**
     * Check if live class is live.
     */
    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    /**
     * Check if live class has ended.
     */
    public function hasEnded(): bool
    {
        return $this->status === 'ended';
    }

    /**
     * Check if live class is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Check if live class is starting soon.
     */
    public function isStartingSoon(): bool
    {
        if (!$this->start_time) {
            return false;
        }

        $now = now();
        $startTime = $this->start_time;
        
        // Check if class starts within the next 15 minutes
        return $now->diffInMinutes($startTime, false) <= 15 && $now->diffInMinutes($startTime, false) >= 0;
    }

    /**
     * Check if live class is overdue.
     */
    public function isOverdue(): bool
    {
        if (!$this->start_time) {
            return false;
        }

        return now()->isAfter($this->start_time);
    }

    /**
     * Check if live class is full.
     */
    public function isFull(): bool
    {
        return $this->current_participants >= $this->max_participants;
    }

    /**
     * Check if user can join.
     */
    public function canUserJoin(int $userId): bool
    {
        // Check if user is enrolled in the course
        if (!$this->course->enrollments()->where('user_id', $userId)->exists()) {
            return false;
        }

        // Check if class is not full
        if ($this->isFull()) {
            return false;
        }

        // Check if class is scheduled or live
        if (!in_array($this->status, ['scheduled', 'live'])) {
            return false;
        }

        return true;
    }

    /**
     * Get formatted start time.
     */
    public function getFormattedStartTimeAttribute(): string
    {
        return $this->start_time ? $this->start_time->format('M d, Y H:i') : 'TBD';
    }

    /**
     * Get formatted end time.
     */
    public function getFormattedEndTimeAttribute(): string
    {
        return $this->end_time ? $this->end_time->format('M d, Y H:i') : 'TBD';
    }

    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration) {
            return 'TBD';
        }

        $hours = floor($this->duration / 60);
        $minutes = $this->duration % 60;

        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm';
        }

        return $minutes . 'm';
    }

    /**
     * Get time until start.
     */
    public function getTimeUntilStartAttribute(): ?string
    {
        if (!$this->start_time) {
            return null;
        }

        $now = now();
        $startTime = $this->start_time;

        if ($now->isAfter($startTime)) {
            return 'Started';
        }

        $diff = $now->diff($startTime);
        
        if ($diff->days > 0) {
            return $diff->days . ' days';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hours';
        } else {
            return $diff->i . ' minutes';
        }
    }

    /**
     * Get status badge.
     */
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'scheduled' => 'bg-info',
            'live' => 'bg-success',
            'ended' => 'bg-secondary',
            'cancelled' => 'bg-danger',
            default => 'bg-secondary'
        };
    }

    /**
     * Scope to get scheduled classes.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope to get live classes.
     */
    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    /**
     * Scope to get ended classes.
     */
    public function scopeEnded($query)
    {
        return $query->where('status', 'ended');
    }

    /**
     * Scope to get classes by course.
     */
    public function scopeByCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    /**
     * Scope to get classes by instructor.
     */
    public function scopeByInstructor($query, int $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }

    /**
     * Scope to get upcoming classes.
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', now());
    }

    /**
     * Scope to get today's classes.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('start_time', today());
    }
}










