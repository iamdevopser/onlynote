<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningPath extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category_id',
        'difficulty_level', // beginner, intermediate, advanced, expert
        'estimated_duration', // in hours
        'is_active',
        'is_featured',
        'sort_order',
        'metadata'
    ];

    protected $casts = [
        'estimated_duration' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array'
    ];

    /**
     * Get the category that owns the learning path.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the courses for this learning path.
     */
    public function courses(): HasMany
    {
        return $this->hasMany(LearningPathCourse::class);
    }

    /**
     * Get the users following this learning path.
     */
    public function followers(): HasMany
    {
        return $this->hasMany(LearningPathFollower::class);
    }

    /**
     * Check if learning path is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if learning path is featured.
     */
    public function isFeatured(): bool
    {
        return $this->is_featured;
    }

    /**
     * Get difficulty level badge.
     */
    public function getDifficultyBadgeAttribute(): string
    {
        return match($this->difficulty_level) {
            'beginner' => 'bg-success',
            'intermediate' => 'bg-warning',
            'advanced' => 'bg-danger',
            'expert' => 'bg-dark',
            default => 'bg-secondary'
        };
    }

    /**
     * Get difficulty level text.
     */
    public function getDifficultyTextAttribute(): string
    {
        return ucfirst($this->difficulty_level);
    }

    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->estimated_duration) {
            return 'TBD';
        }

        if ($this->estimated_duration >= 24) {
            $days = floor($this->estimated_duration / 24);
            $hours = $this->estimated_duration % 24;
            
            if ($hours > 0) {
                return $days . 'd ' . $hours . 'h';
            }
            return $days . ' days';
        }

        return $this->estimated_duration . ' hours';
    }

    /**
     * Get total courses count.
     */
    public function getTotalCoursesAttribute(): int
    {
        return $this->courses()->count();
    }

    /**
     * Get completed courses count for a user.
     */
    public function getCompletedCoursesCount(User $user): int
    {
        $courseIds = $this->courses()->pluck('course_id');
        return $user->enrollments()
            ->whereIn('course_id', $courseIds)
            ->where('status', 'completed')
            ->count();
    }

    /**
     * Get user's progress percentage.
     */
    public function getUserProgressPercentage(User $user): int
    {
        $totalCourses = $this->total_courses;
        if ($totalCourses === 0) {
            return 0;
        }

        $completedCourses = $this->getCompletedCoursesCount($user);
        return round(($completedCourses / $totalCourses) * 100);
    }

    /**
     * Check if user can access this learning path.
     */
    public function canUserAccess(User $user): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        // Check if user meets prerequisites
        return $this->checkPrerequisites($user);
    }

    /**
     * Check if user meets prerequisites.
     */
    private function checkPrerequisites(User $user): bool
    {
        $prerequisites = $this->metadata['prerequisites'] ?? [];
        
        foreach ($prerequisites as $prerequisite) {
            if (!$this->evaluatePrerequisite($user, $prerequisite)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single prerequisite.
     */
    private function evaluatePrerequisite(User $user, array $prerequisite): bool
    {
        $type = $prerequisite['type'] ?? '';
        $value = $prerequisite['value'] ?? 0;
        $operator = $prerequisite['operator'] ?? '>=';

        switch ($type) {
            case 'courses_completed':
                $userValue = $user->enrollments()->where('status', 'completed')->count();
                break;
            case 'minimum_level':
                $userValue = $user->level ?? 1;
                break;
            case 'minimum_points':
                $userValue = $user->points ?? 0;
                break;
            case 'badges_count':
                $userValue = $user->badges()->count();
                break;
            default:
                return true;
        }

        return $this->evaluateCondition($userValue, $operator, $value);
    }

    /**
     * Evaluate condition based on operator.
     */
    private function evaluateCondition($userValue, string $operator, $targetValue): bool
    {
        return match($operator) {
            '>=' => $userValue >= $targetValue,
            '<=' => $userValue <= $targetValue,
            '==' => $userValue == $targetValue,
            '>' => $userValue > $targetValue,
            '<' => $userValue < $targetValue,
            default => true
        };
    }

    /**
     * Get next recommended course for user.
     */
    public function getNextRecommendedCourse(User $user): ?Course
    {
        $orderedCourses = $this->courses()->orderBy('sort_order')->get();
        
        foreach ($orderedCourses as $learningPathCourse) {
            $course = $learningPathCourse->course;
            
            // Check if user is enrolled but not completed
            $enrollment = $user->enrollments()->where('course_id', $course->id)->first();
            
            if (!$enrollment) {
                // User not enrolled - check if they can enroll
                if ($course->canUserEnroll($user->id)) {
                    return $course;
                }
            } elseif ($enrollment->status !== 'completed') {
                // User enrolled but not completed - this is the next course
                return $course;
            }
        }

        return null;
    }

    /**
     * Scope to get active learning paths.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get featured learning paths.
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope to get learning paths by category.
     */
    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope to get learning paths by difficulty.
     */
    public function scopeByDifficulty($query, string $difficulty)
    {
        return $query->where('difficulty_level', $difficulty);
    }

    /**
     * Scope to get learning paths by duration range.
     */
    public function scopeByDurationRange($query, int $minHours, int $maxHours)
    {
        return $query->whereBetween('estimated_duration', [$minHours, $maxHours]);
    }
}










