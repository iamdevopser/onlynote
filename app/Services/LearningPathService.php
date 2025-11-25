<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\LearningPath;
use App\Models\LearningPathCourse;
use App\Models\LearningPathFollower;
use App\Models\Course;
use App\Models\User;

class LearningPathService
{
    /**
     * Create a new learning path
     */
    public function createLearningPath(array $data): LearningPath
    {
        DB::beginTransaction();
        try {
            $learningPath = LearningPath::create([
                'name' => $data['name'],
                'description' => $data['description'],
                'category_id' => $data['category_id'],
                'difficulty_level' => $data['difficulty_level'] ?? 'beginner',
                'estimated_duration' => $data['estimated_duration'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'is_featured' => $data['is_featured'] ?? false,
                'sort_order' => $data['sort_order'] ?? 0,
                'metadata' => $data['metadata'] ?? []
            ]);

            // Add courses if provided
            if (isset($data['courses']) && is_array($data['courses'])) {
                foreach ($data['courses'] as $index => $courseData) {
                    $learningPath->courses()->create([
                        'course_id' => $courseData['course_id'],
                        'sort_order' => $courseData['sort_order'] ?? $index + 1,
                        'is_required' => $courseData['is_required'] ?? true,
                        'metadata' => $courseData['metadata'] ?? []
                    ]);
                }
            }

            DB::commit();
            
            Log::info("Learning path created successfully", [
                'learning_path_id' => $learningPath->id,
                'name' => $learningPath->name
            ]);

            return $learningPath;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create learning path: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update a learning path
     */
    public function updateLearningPath(LearningPath $learningPath, array $data): LearningPath
    {
        DB::beginTransaction();
        try {
            $learningPath->update($data);

            // Update courses if provided
            if (isset($data['courses']) && is_array($data['courses'])) {
                // Remove existing courses
                $learningPath->courses()->delete();
                
                // Add new courses
                foreach ($data['courses'] as $index => $courseData) {
                    $learningPath->courses()->create([
                        'course_id' => $courseData['course_id'],
                        'sort_order' => $courseData['sort_order'] ?? $index + 1,
                        'is_required' => $courseData['is_required'] ?? true,
                        'metadata' => $courseData['metadata'] ?? []
                    ]);
                }
            }

            DB::commit();
            
            Log::info("Learning path updated successfully", [
                'learning_path_id' => $learningPath->id
            ]);

            return $learningPath;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to update learning path: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete a learning path
     */
    public function deleteLearningPath(LearningPath $learningPath): bool
    {
        try {
            // Check if learning path has followers
            if ($learningPath->followers()->count() > 0) {
                throw new \Exception('Cannot delete learning path that has followers.');
            }

            // Delete related data
            $learningPath->courses()->delete();
            $learningPath->delete();
            
            Log::info("Learning path deleted successfully", [
                'learning_path_id' => $learningPath->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to delete learning path: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add course to learning path
     */
    public function addCourseToPath(LearningPath $learningPath, array $courseData): LearningPathCourse
    {
        try {
            // Get next sort order
            $nextSortOrder = $learningPath->courses()->max('sort_order') + 1;

            $learningPathCourse = $learningPath->courses()->create([
                'course_id' => $courseData['course_id'],
                'sort_order' => $courseData['sort_order'] ?? $nextSortOrder,
                'is_required' => $courseData['is_required'] ?? true,
                'metadata' => $courseData['metadata'] ?? []
            ]);

            Log::info("Course added to learning path successfully", [
                'learning_path_id' => $learningPath->id,
                'course_id' => $courseData['course_id']
            ]);

            return $learningPathCourse;

        } catch (\Exception $e) {
            Log::error("Failed to add course to learning path: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Remove course from learning path
     */
    public function removeCourseFromPath(LearningPath $learningPath, int $courseId): bool
    {
        try {
            $learningPathCourse = $learningPath->courses()->where('course_id', $courseId)->first();
            
            if (!$learningPathCourse) {
                throw new \Exception('Course not found in learning path.');
            }

            $learningPathCourse->delete();
            
            Log::info("Course removed from learning path successfully", [
                'learning_path_id' => $learningPath->id,
                'course_id' => $courseId
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to remove course from learning path: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Reorder courses in learning path
     */
    public function reorderCourses(LearningPath $learningPath, array $courseOrder): bool
    {
        try {
            foreach ($courseOrder as $index => $courseId) {
                $learningPath->courses()
                    ->where('course_id', $courseId)
                    ->update(['sort_order' => $index + 1]);
            }
            
            Log::info("Learning path courses reordered successfully", [
                'learning_path_id' => $learningPath->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to reorder learning path courses: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Follow a learning path
     */
    public function followLearningPath(LearningPath $learningPath, int $userId): LearningPathFollower
    {
        try {
            // Check if user already follows this path
            $existingFollower = $learningPath->followers()->where('user_id', $userId)->first();
            if ($existingFollower) {
                return $existingFollower;
            }

            // Check if user can access this learning path
            $user = User::find($userId);
            if (!$learningPath->canUserAccess($user)) {
                throw new \Exception('User does not meet prerequisites for this learning path.');
            }

            $follower = $learningPath->followers()->create([
                'user_id' => $userId,
                'started_at' => now(),
                'status' => 'active',
                'metadata' => []
            ]);

            Log::info("User started following learning path", [
                'learning_path_id' => $learningPath->id,
                'user_id' => $userId
            ]);

            return $follower;

        } catch (\Exception $e) {
            Log::error("Failed to follow learning path: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Unfollow a learning path
     */
    public function unfollowLearningPath(LearningPath $learningPath, int $userId): bool
    {
        try {
            $follower = $learningPath->followers()->where('user_id', $userId)->first();
            
            if (!$follower) {
                throw new \Exception('User is not following this learning path.');
            }

            $follower->update([
                'status' => 'inactive',
                'stopped_at' => now()
            ]);
            
            Log::info("User stopped following learning path", [
                'learning_path_id' => $learningPath->id,
                'user_id' => $userId
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to unfollow learning path: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get learning paths for a user
     */
    public function getUserLearningPaths(int $userId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = LearningPath::with(['category', 'courses.course', 'followers'])
            ->whereHas('followers', function($q) use ($userId) {
                $q->where('user_id', $userId)->where('status', 'active');
            });

        // Apply filters
        if (isset($filters['category_id'])) {
            $query->byCategory($filters['category_id']);
        }

        if (isset($filters['difficulty'])) {
            $query->byDifficulty($filters['difficulty']);
        }

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->active();
            }
        }

        return $query->orderBy('sort_order', 'asc')->get();
    }

    /**
     * Get recommended learning paths for a user
     */
    public function getRecommendedLearningPaths(User $user, int $limit = 5): \Illuminate\Database\Eloquent\Collection
    {
        $query = LearningPath::with(['category', 'courses'])
            ->active()
            ->whereDoesntHave('followers', function($q) use ($user) {
                $q->where('user_id', $user->id);
            });

        // Filter by user's skill level
        $userLevel = $user->level ?? 1;
        $userPoints = $user->points ?? 0;

        if ($userLevel <= 5) {
            $query->whereIn('difficulty_level', ['beginner', 'intermediate']);
        } elseif ($userLevel <= 15) {
            $query->whereIn('difficulty_level', ['intermediate', 'advanced']);
        } else {
            $query->whereIn('difficulty_level', ['advanced', 'expert']);
        }

        // Filter by prerequisites
        $query->where(function($q) use ($user, $userPoints) {
            $q->whereNull('metadata->prerequisites')
              ->orWhereJsonLength('metadata->prerequisites', 0)
              ->orWhereJsonContains('metadata->prerequisites', [
                  ['type' => 'minimum_points', 'value' => $userPoints, 'operator' => '<=']
              ]);
        });

        return $query->featured()
            ->orderBy('sort_order', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get learning path progress for a user
     */
    public function getLearningPathProgress(LearningPath $learningPath, User $user): array
    {
        $totalCourses = $learningPath->total_courses;
        $completedCourses = $learningPath->getCompletedCoursesCount($user);
        $progressPercentage = $learningPath->getUserProgressPercentage($user);
        
        $nextCourse = $learningPath->getNextRecommendedCourse($user);
        $estimatedTimeRemaining = $this->calculateEstimatedTimeRemaining($learningPath, $user);

        return [
            'total_courses' => $totalCourses,
            'completed_courses' => $completedCourses,
            'remaining_courses' => $totalCourses - $completedCourses,
            'progress_percentage' => $progressPercentage,
            'next_course' => $nextCourse,
            'estimated_time_remaining' => $estimatedTimeRemaining,
            'is_completed' => $completedCourses >= $totalCourses
        ];
    }

    /**
     * Calculate estimated time remaining for user to complete learning path
     */
    private function calculateEstimatedTimeRemaining(LearningPath $learningPath, User $user): int
    {
        $remainingCourses = $learningPath->total_courses - $learningPath->getCompletedCoursesCount($user);
        
        // Get average course duration from remaining courses
        $remainingCourseIds = $learningPath->courses()
            ->whereNotIn('course_id', $user->enrollments()
                ->where('status', 'completed')
                ->pluck('course_id'))
            ->pluck('course_id');

        $averageDuration = Course::whereIn('id', $remainingCourseIds)
            ->avg('estimated_duration') ?? 0;

        return round($remainingCourses * $averageDuration);
    }

    /**
     * Get learning path statistics
     */
    public function getLearningPathStatistics(int $learningPathId): array
    {
        $learningPath = LearningPath::with(['courses', 'followers'])->findOrFail($learningPathId);
        
        $totalFollowers = $learningPath->followers()->count();
        $activeFollowers = $learningPath->followers()->where('status', 'active')->count();
        $completedFollowers = $learningPath->followers()
            ->whereHas('user.enrollments', function($q) use ($learningPath) {
                $courseIds = $learningPath->courses()->pluck('course_id');
                $q->whereIn('course_id', $courseIds)->where('status', 'completed');
            })->count();

        $averageProgress = $learningPath->followers()
            ->where('status', 'active')
            ->get()
            ->avg(function($follower) use ($learningPath) {
                return $learningPath->getUserProgressPercentage($follower->user);
            }) ?? 0;

        return [
            'total_followers' => $totalFollowers,
            'active_followers' => $activeFollowers,
            'completed_followers' => $completedFollowers,
            'average_progress' => round($averageProgress, 2),
            'completion_rate' => $totalFollowers > 0 ? 
                round(($completedFollowers / $totalFollowers) * 100, 2) : 0
        ];
    }
}










