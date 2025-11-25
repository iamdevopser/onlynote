<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Course;
use App\Models\CoursePrerequisite;
use App\Models\CourseEnrollment;
use App\Models\User;

class CoursePrerequisiteService
{
    protected $prerequisiteTypes = [
        'course_completion' => 'Course Completion',
        'course_enrollment' => 'Course Enrollment',
        'minimum_score' => 'Minimum Score',
        'time_requirement' => 'Time Requirement',
        'skill_assessment' => 'Skill Assessment',
        'certification' => 'Certification',
        'experience_level' => 'Experience Level',
        'custom_requirement' => 'Custom Requirement'
    ];

    protected $evaluationMethods = [
        'automatic' => 'Automatic Evaluation',
        'manual_review' => 'Manual Review',
        'admin_approval' => 'Admin Approval',
        'instructor_approval' => 'Instructor Approval'
    ];

    /**
     * Create course prerequisite
     */
    public function createPrerequisite($data)
    {
        try {
            // Validate prerequisite data
            $validation = $this->validatePrerequisiteData($data);
            if (!$validation['valid']) {
                return $validation;
            }

            // Check for circular dependencies
            if ($this->hasCircularDependency($data['course_id'], $data['prerequisite_course_id'])) {
                return [
                    'success' => false,
                    'message' => 'Circular dependency detected'
                ];
            }

            $prerequisite = CoursePrerequisite::create([
                'course_id' => $data['course_id'],
                'prerequisite_course_id' => $data['prerequisite_course_id'],
                'prerequisite_type' => $data['prerequisite_type'],
                'requirement_value' => $data['requirement_value'] ?? null,
                'evaluation_method' => $data['evaluation_method'] ?? 'automatic',
                'is_mandatory' => $data['is_mandatory'] ?? true,
                'order' => $data['order'] ?? 0,
                'description' => $data['description'] ?? '',
                'metadata' => $data['metadata'] ?? []
            ]);

            // Clear cache
            $this->clearPrerequisiteCache($data['course_id']);

            Log::info("Course prerequisite created", [
                'prerequisite_id' => $prerequisite->id,
                'course_id' => $data['course_id'],
                'prerequisite_course_id' => $data['prerequisite_course_id']
            ]);

            return [
                'success' => true,
                'prerequisite' => $prerequisite,
                'message' => 'Prerequisite created successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to create prerequisite: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create prerequisite'
            ];
        }
    }

    /**
     * Validate prerequisite data
     */
    private function validatePrerequisiteData($data)
    {
        $rules = [
            'course_id' => 'required|exists:courses,id',
            'prerequisite_course_id' => 'required|exists:courses,id',
            'prerequisite_type' => 'required|in:' . implode(',', array_keys($this->prerequisiteTypes)),
            'evaluation_method' => 'in:' . implode(',', array_keys($this->evaluationMethods)),
            'requirement_value' => 'nullable|numeric|min:0',
            'order' => 'integer|min:0'
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->all()
            ];
        }

        // Check if prerequisite course is different from main course
        if ($data['course_id'] === $data['prerequisite_course_id']) {
            return [
                'valid' => false,
                'errors' => ['Course cannot be a prerequisite for itself']
            ];
        }

        return ['valid' => true];
    }

    /**
     * Check for circular dependencies
     */
    private function hasCircularDependency($courseId, $prerequisiteCourseId)
    {
        $visited = [];
        return $this->checkCircularDependencyDFS($prerequisiteCourseId, $courseId, $visited);
    }

    /**
     * Check circular dependency using DFS
     */
    private function checkCircularDependencyDFS($currentCourseId, $targetCourseId, &$visited)
    {
        if ($currentCourseId === $targetCourseId) {
            return true;
        }

        if (in_array($currentCourseId, $visited)) {
            return false;
        }

        $visited[] = $currentCourseId;

        $prerequisites = CoursePrerequisite::where('course_id', $currentCourseId)->get();

        foreach ($prerequisites as $prerequisite) {
            if ($this->checkCircularDependencyDFS($prerequisite->prerequisite_course_id, $targetCourseId, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get course prerequisites
     */
    public function getCoursePrerequisites($courseId)
    {
        $cacheKey = "course_prerequisites_{$courseId}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $prerequisites = CoursePrerequisite::where('course_id', $courseId)
            ->with(['prerequisiteCourse', 'course'])
            ->orderBy('order', 'asc')
            ->get();

        Cache::put($cacheKey, $prerequisites, 3600);

        return $prerequisites;
    }

    /**
     * Get prerequisite by ID
     */
    public function getPrerequisite($prerequisiteId)
    {
        return CoursePrerequisite::with(['course', 'prerequisiteCourse'])->find($prerequisiteId);
    }

    /**
     * Update prerequisite
     */
    public function updatePrerequisite($prerequisiteId, $data)
    {
        try {
            $prerequisite = CoursePrerequisite::find($prerequisiteId);
            
            if (!$prerequisite) {
                return [
                    'success' => false,
                    'message' => 'Prerequisite not found'
                ];
            }

            // Check for circular dependencies if prerequisite course is being changed
            if (isset($data['prerequisite_course_id']) && $data['prerequisite_course_id'] !== $prerequisite->prerequisite_course_id) {
                if ($this->hasCircularDependency($prerequisite->course_id, $data['prerequisite_course_id'])) {
                    return [
                        'success' => false,
                        'message' => 'Circular dependency detected'
                    ];
                }
            }

            // Update fields
            $updatableFields = [
                'prerequisite_course_id', 'prerequisite_type', 'requirement_value',
                'evaluation_method', 'is_mandatory', 'order', 'description', 'metadata'
            ];

            foreach ($updatableFields as $field) {
                if (isset($data[$field])) {
                    $prerequisite->$field = $data[$field];
                }
            }

            $prerequisite->updated_at = now();
            $prerequisite->save();

            // Clear cache
            $this->clearPrerequisiteCache($prerequisite->course_id);

            Log::info("Course prerequisite updated", [
                'prerequisite_id' => $prerequisiteId,
                'course_id' => $prerequisite->course_id
            ]);

            return [
                'success' => true,
                'prerequisite' => $prerequisite,
                'message' => 'Prerequisite updated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to update prerequisite: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update prerequisite'
            ];
        }
    }

    /**
     * Delete prerequisite
     */
    public function deletePrerequisite($prerequisiteId)
    {
        try {
            $prerequisite = CoursePrerequisite::find($prerequisiteId);
            
            if (!$prerequisite) {
                return [
                    'success' => false,
                    'message' => 'Prerequisite not found'
                ];
            }

            $courseId = $prerequisite->course_id;
            $prerequisite->delete();

            // Clear cache
            $this->clearPrerequisiteCache($courseId);

            Log::info("Course prerequisite deleted", [
                'prerequisite_id' => $prerequisiteId,
                'course_id' => $courseId
            ]);

            return [
                'success' => true,
                'message' => 'Prerequisite deleted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to delete prerequisite: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete prerequisite'
            ];
        }
    }

    /**
     * Check if user meets prerequisites
     */
    public function checkUserPrerequisites($userId, $courseId)
    {
        try {
            $prerequisites = $this->getCoursePrerequisites($courseId);
            
            if ($prerequisites->isEmpty()) {
                return [
                    'meets_prerequisites' => true,
                    'prerequisites_met' => [],
                    'prerequisites_not_met' => [],
                    'overall_status' => 'eligible'
                ];
            }

            $prerequisitesMet = [];
            $prerequisitesNotMet = [];
            $allMet = true;

            foreach ($prerequisites as $prerequisite) {
                $result = $this->evaluatePrerequisite($userId, $prerequisite);
                
                if ($result['met']) {
                    $prerequisitesMet[] = [
                        'prerequisite' => $prerequisite,
                        'result' => $result
                    ];
                } else {
                    $prerequisitesNotMet[] = [
                        'prerequisite' => $prerequisite,
                        'result' => $result
                    ];
                    
                    if ($prerequisite->is_mandatory) {
                        $allMet = false;
                    }
                }
            }

            $overallStatus = $allMet ? 'eligible' : 'not_eligible';

            return [
                'meets_prerequisites' => $allMet,
                'prerequisites_met' => $prerequisitesMet,
                'prerequisites_not_met' => $prerequisitesNotMet,
                'overall_status' => $overallStatus,
                'total_prerequisites' => $prerequisites->count(),
                'met_count' => count($prerequisitesMet),
                'not_met_count' => count($prerequisitesNotMet)
            ];

        } catch (\Exception $e) {
            Log::error("Failed to check user prerequisites: " . $e->getMessage());
            return [
                'meets_prerequisites' => false,
                'error' => 'Failed to check prerequisites'
            ];
        }
    }

    /**
     * Evaluate individual prerequisite
     */
    private function evaluatePrerequisite($userId, $prerequisite)
    {
        try {
            switch ($prerequisite->prerequisite_type) {
                case 'course_completion':
                    return $this->evaluateCourseCompletion($userId, $prerequisite);
                
                case 'course_enrollment':
                    return $this->evaluateCourseEnrollment($userId, $prerequisite);
                
                case 'minimum_score':
                    return $this->evaluateMinimumScore($userId, $prerequisite);
                
                case 'time_requirement':
                    return $this->evaluateTimeRequirement($userId, $prerequisite);
                
                case 'skill_assessment':
                    return $this->evaluateSkillAssessment($userId, $prerequisite);
                
                case 'certification':
                    return $this->evaluateCertification($userId, $prerequisite);
                
                case 'experience_level':
                    return $this->evaluateExperienceLevel($userId, $prerequisite);
                
                case 'custom_requirement':
                    return $this->evaluateCustomRequirement($userId, $prerequisite);
                
                default:
                    return [
                        'met' => false,
                        'message' => 'Unknown prerequisite type',
                        'details' => null
                    ];
            }

        } catch (\Exception $e) {
            Log::error("Failed to evaluate prerequisite: " . $e->getMessage());
            return [
                'met' => false,
                'message' => 'Evaluation failed',
                'details' => null
            ];
        }
    }

    /**
     * Evaluate course completion prerequisite
     */
    private function evaluateCourseCompletion($userId, $prerequisite)
    {
        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $prerequisite->prerequisite_course_id)
            ->where('status', 'completed')
            ->first();

        if (!$enrollment) {
            return [
                'met' => false,
                'message' => 'Course not completed',
                'details' => [
                    'required_course' => $prerequisite->prerequisiteCourse->title,
                    'user_status' => 'not_enrolled_or_not_completed'
                ]
            ];
        }

        return [
            'met' => true,
            'message' => 'Course completed successfully',
            'details' => [
                'required_course' => $prerequisite->prerequisiteCourse->title,
                'completion_date' => $enrollment->completed_at,
                'final_score' => $enrollment->final_score
            ]
        ];
    }

    /**
     * Evaluate course enrollment prerequisite
     */
    private function evaluateCourseEnrollment($userId, $prerequisite)
    {
        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $prerequisite->prerequisite_course_id)
            ->whereIn('status', ['enrolled', 'in_progress', 'completed'])
            ->first();

        if (!$enrollment) {
            return [
                'met' => false,
                'message' => 'Course not enrolled',
                'details' => [
                    'required_course' => $prerequisite->prerequisiteCourse->title,
                    'user_status' => 'not_enrolled'
                ]
            ];
        }

        return [
            'met' => true,
            'message' => 'Course enrolled',
            'details' => [
                'required_course' => $prerequisite->prerequisiteCourse->title,
                'enrollment_date' => $enrollment->enrolled_at,
                'current_status' => $enrollment->status
            ]
        ];
    }

    /**
     * Evaluate minimum score prerequisite
     */
    private function evaluateMinimumScore($userId, $prerequisite)
    {
        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $prerequisite->prerequisite_course_id)
            ->where('status', 'completed')
            ->first();

        if (!$enrollment) {
            return [
                'met' => false,
                'message' => 'Course not completed',
                'details' => [
                    'required_course' => $prerequisite->prerequisiteCourse->title,
                    'user_status' => 'not_completed'
                ]
            ];
        }

        $requiredScore = $prerequisite->requirement_value ?? 70;
        $userScore = $enrollment->final_score ?? 0;

        if ($userScore < $requiredScore) {
            return [
                'met' => false,
                'message' => 'Minimum score not met',
                'details' => [
                    'required_course' => $prerequisite->prerequisiteCourse->title,
                    'required_score' => $requiredScore,
                    'user_score' => $userScore,
                    'difference' => $requiredScore - $userScore
                ]
            ];
        }

        return [
            'met' => true,
            'message' => 'Minimum score requirement met',
            'details' => [
                'required_course' => $prerequisite->prerequisiteCourse->title,
                'required_score' => $requiredScore,
                'user_score' => $userScore,
                'excess' => $userScore - $requiredScore
            ]
        ];
    }

    /**
     * Evaluate time requirement prerequisite
     */
    private function evaluateTimeRequirement($userId, $prerequisite)
    {
        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $prerequisite->prerequisite_course_id)
            ->first();

        if (!$enrollment) {
            return [
                'met' => false,
                'message' => 'Course not enrolled',
                'details' => [
                    'required_course' => $prerequisite->prerequisiteCourse->title,
                    'user_status' => 'not_enrolled'
                ]
            ];
        }

        $requiredTime = $prerequisite->requirement_value ?? 30; // days
        $enrollmentDate = $enrollment->enrolled_at;
        $timeEnrolled = now()->diffInDays($enrollmentDate);

        if ($timeEnrolled < $requiredTime) {
            return [
                'met' => false,
                'message' => 'Time requirement not met',
                'details' => [
                    'required_course' => $prerequisite->prerequisiteCourse->title,
                    'required_days' => $requiredTime,
                    'days_enrolled' => $timeEnrolled,
                    'remaining_days' => $requiredTime - $timeEnrolled
                ]
            ];
        }

        return [
            'met' => true,
            'message' => 'Time requirement met',
            'details' => [
                'required_course' => $prerequisite->prerequisiteCourse->title,
                'required_days' => $requiredTime,
                'days_enrolled' => $timeEnrolled,
                'excess_days' => $timeEnrolled - $requiredTime
            ]
        ];
    }

    /**
     * Evaluate skill assessment prerequisite
     */
    private function evaluateSkillAssessment($userId, $prerequisite)
    {
        // This would integrate with skill assessment system
        // For now, return a placeholder evaluation
        return [
            'met' => false,
            'message' => 'Skill assessment not implemented',
            'details' => [
                'assessment_type' => 'skill_test',
                'status' => 'pending'
            ]
        ];
    }

    /**
     * Evaluate certification prerequisite
     */
    private function evaluateCertification($userId, $prerequisite)
    {
        // This would integrate with certification system
        // For now, return a placeholder evaluation
        return [
            'met' => false,
            'message' => 'Certification evaluation not implemented',
            'details' => [
                'certification_type' => 'required_certification',
                'status' => 'pending'
            ]
        ];
    }

    /**
     * Evaluate experience level prerequisite
     */
    private function evaluateExperienceLevel($userId, $prerequisite)
    {
        // This would evaluate user's experience level
        // For now, return a placeholder evaluation
        return [
            'met' => false,
            'message' => 'Experience level evaluation not implemented',
            'details' => [
                'experience_type' => 'required_experience',
                'status' => 'pending'
            ]
        ];
    }

    /**
     * Evaluate custom requirement prerequisite
     */
    private function evaluateCustomRequirement($userId, $prerequisite)
    {
        // This would evaluate custom requirements based on metadata
        $customLogic = $prerequisite->metadata['custom_logic'] ?? null;
        
        if (!$customLogic) {
            return [
                'met' => false,
                'message' => 'Custom requirement logic not defined',
                'details' => null
            ];
        }

        // For now, return a placeholder evaluation
        return [
            'met' => false,
            'message' => 'Custom requirement evaluation not implemented',
            'details' => [
                'custom_logic' => $customLogic,
                'status' => 'pending'
            ]
        ];
    }

    /**
     * Get prerequisite path
     */
    public function getPrerequisitePath($courseId)
    {
        $prerequisites = $this->getCoursePrerequisites($courseId);
        $path = [];

        foreach ($prerequisites as $prerequisite) {
            $path[] = [
                'course' => $prerequisite->prerequisiteCourse,
                'prerequisite' => $prerequisite,
                'sub_prerequisites' => $this->getPrerequisitePath($prerequisite->prerequisite_course_id)
            ];
        }

        return $path;
    }

    /**
     * Get prerequisite statistics
     */
    public function getPrerequisiteStats($courseId = null)
    {
        $query = CoursePrerequisite::query();

        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        $stats = [
            'total_prerequisites' => $query->count(),
            'prerequisites_by_type' => $query->selectRaw('prerequisite_type, COUNT(*) as count')
                ->groupBy('prerequisite_type')
                ->pluck('count', 'prerequisite_type'),
            'mandatory_prerequisites' => $query->where('is_mandatory', true)->count(),
            'optional_prerequisites' => $query->where('is_mandatory', false)->count(),
            'evaluation_methods' => $query->selectRaw('evaluation_method, COUNT(*) as count')
                ->groupBy('evaluation_method')
                ->pluck('count', 'evaluation_method'),
            'courses_with_prerequisites' => CoursePrerequisite::distinct('course_id')->count('course_id'),
            'average_prerequisites_per_course' => $query->selectRaw('course_id, COUNT(*) as prerequisite_count')
                ->groupBy('course_id')
                ->avg('prerequisite_count')
        ];

        return $stats;
    }

    /**
     * Clear prerequisite cache
     */
    private function clearPrerequisiteCache($courseId)
    {
        Cache::forget("course_prerequisites_{$courseId}");
    }

    /**
     * Get prerequisite types
     */
    public function getPrerequisiteTypes()
    {
        return $this->prerequisiteTypes;
    }

    /**
     * Get evaluation methods
     */
    public function getEvaluationMethods()
    {
        return $this->evaluationMethods;
    }

    /**
     * Validate prerequisite chain
     */
    public function validatePrerequisiteChain($courseId)
    {
        $prerequisites = $this->getCoursePrerequisites($courseId);
        $issues = [];

        foreach ($prerequisites as $prerequisite) {
            // Check if prerequisite course exists
            if (!$prerequisite->prerequisiteCourse) {
                $issues[] = [
                    'type' => 'missing_course',
                    'prerequisite_id' => $prerequisite->id,
                    'message' => 'Prerequisite course not found'
                ];
                continue;
            }

            // Check for circular dependencies
            if ($this->hasCircularDependency($courseId, $prerequisite->prerequisite_course_id)) {
                $issues[] = [
                    'type' => 'circular_dependency',
                    'prerequisite_id' => $prerequisite->id,
                    'message' => 'Circular dependency detected'
                ];
            }

            // Check prerequisite course status
            if ($prerequisite->prerequisiteCourse->status !== 'published') {
                $issues[] = [
                    'type' => 'unpublished_course',
                    'prerequisite_id' => $prerequisite->id,
                    'message' => 'Prerequisite course is not published'
                ];
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'total_prerequisites' => $prerequisites->count(),
            'valid_prerequisites' => $prerequisites->count() - count($issues)
        ];
    }
} 