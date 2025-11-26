<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'lesson_id',
        'title',
        'description',
        'instructions',
        'due_date',
        'points',
        'submission_type', // file, text, link, mixed
        'max_file_size',
        'allowed_file_types',
        'is_active',
        'allow_late_submission',
        'late_submission_penalty',
        'requires_peer_review',
        'peer_review_deadline',
        'metadata'
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'is_active' => 'boolean',
        'allow_late_submission' => 'boolean',
        'requires_peer_review' => 'boolean',
        'peer_review_deadline' => 'datetime',
        'allowed_file_types' => 'array',
        'metadata' => 'array'
    ];

    /**
     * Get the course that owns the assignment.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the lesson that owns the assignment.
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Get the submissions for this assignment.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    /**
     * Get the criteria for this assignment.
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(AssignmentCriterion::class);
    }

    /**
     * Get the resources for this assignment.
     */
    public function resources(): HasMany
    {
        return $this->hasMany(AssignmentResource::class);
    }

    /**
     * Check if assignment is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if assignment is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->due_date && now()->isAfter($this->due_date);
    }

    /**
     * Check if late submission is allowed.
     */
    public function allowsLateSubmission(): bool
    {
        return $this->allow_late_submission;
    }

    /**
     * Get submission count for a user.
     */
    public function getUserSubmissionCount(int $userId): int
    {
        return $this->submissions()->where('user_id', $userId)->count();
    }

    /**
     * Check if user can submit.
     */
    public function canUserSubmit(int $userId): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        // Check if user is enrolled in the course
        if (!$this->course->enrollments()->where('user_id', $userId)->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Get formatted due date.
     */
    public function getFormattedDueDateAttribute(): string
    {
        return $this->due_date ? $this->due_date->format('M d, Y H:i') : 'No due date';
    }

    /**
     * Get time remaining until due date.
     */
    public function getTimeRemainingAttribute(): ?string
    {
        if (!$this->due_date) {
            return null;
        }

        $now = now();
        $due = $this->due_date;

        if ($now->isAfter($due)) {
            return 'Overdue';
        }

        $diff = $now->diff($due);
        
        if ($diff->days > 0) {
            return $diff->days . ' days remaining';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hours remaining';
        } else {
            return $diff->i . ' minutes remaining';
        }
    }

    /**
     * Scope to get active assignments.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get assignments by course.
     */
    public function scopeByCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    /**
     * Scope to get assignments by lesson.
     */
    public function scopeByLesson($query, int $lessonId)
    {
        return $query->where('lesson_id', $lessonId);
    }

    /**
     * Scope to get upcoming assignments.
     */
    public function scopeUpcoming($query)
    {
        return $query->where('due_date', '>', now());
    }

    /**
     * Scope to get overdue assignments.
     */
    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now());
    }
}

