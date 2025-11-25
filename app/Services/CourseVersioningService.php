<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseSection;
use App\Models\CourseLesson;
use App\Models\CourseEnrollment;

class CourseVersioningService
{
    protected $versionTypes = [
        'major' => 'Major Version',
        'minor' => 'Minor Version',
        'patch' => 'Patch Version',
        'beta' => 'Beta Version',
        'alpha' => 'Alpha Version'
    ];

    protected $changeTypes = [
        'content_update' => 'Content Update',
        'structure_change' => 'Structure Change',
        'bug_fix' => 'Bug Fix',
        'feature_addition' => 'Feature Addition',
        'improvement' => 'Improvement',
        'breaking_change' => 'Breaking Change'
    ];

    /**
     * Create new course version
     */
    public function createVersion($courseId, $versionData)
    {
        try {
            $course = Course::with(['sections.lessons'])->find($courseId);
            
            if (!$course) {
                return [
                    'success' => false,
                    'message' => 'Course not found'
                ];
            }

            // Validate version data
            $validation = $this->validateVersionData($versionData);
            if (!$validation['valid']) {
                return $validation;
            }

            DB::beginTransaction();

            try {
                // Create version record
                $version = CourseVersion::create([
                    'course_id' => $courseId,
                    'version_number' => $this->generateVersionNumber($course, $versionData['version_type']),
                    'version_type' => $versionData['version_type'],
                    'title' => $versionData['title'],
                    'description' => $versionData['description'],
                    'change_log' => $versionData['change_log'] ?? [],
                    'changes_summary' => $versionData['changes_summary'] ?? '',
                    'is_active' => $versionData['is_active'] ?? false,
                    'is_published' => $versionData['is_published'] ?? false,
                    'created_by' => $versionData['created_by'],
                    'metadata' => $versionData['metadata'] ?? []
                ]);

                // Create version snapshot
                $this->createVersionSnapshot($course, $version);

                // Update course current version if this is active
                if ($version->is_active) {
                    $this->deactivateOtherVersions($courseId);
                    $course->current_version_id = $version->id;
                    $course->save();
                }

                DB::commit();

                Log::info("Course version created", [
                    'course_id' => $courseId,
                    'version_id' => $version->id,
                    'version_number' => $version->version_number
                ]);

                return [
                    'success' => true,
                    'version' => $version,
                    'message' => 'Course version created successfully'
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error("Failed to create course version: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create course version'
            ];
        }
    }

    /**
     * Validate version data
     */
    private function validateVersionData($data)
    {
        $rules = [
            'version_type' => 'required|in:' . implode(',', array_keys($this->versionTypes)),
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'created_by' => 'required|exists:users,id'
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->all()
            ];
        }

        return ['valid' => true];
    }

    /**
     * Generate version number
     */
    private function generateVersionNumber($course, $versionType)
    {
        $latestVersion = $course->versions()
            ->where('version_type', $versionType)
            ->orderBy('version_number', 'desc')
            ->first();

        if (!$latestVersion) {
            switch ($versionType) {
                case 'major':
                    return '1.0.0';
                case 'minor':
                    return '0.1.0';
                case 'patch':
                    return '0.0.1';
                case 'beta':
                    return '1.0.0-beta.1';
                case 'alpha':
                    return '1.0.0-alpha.1';
                default:
                    return '1.0.0';
            }
        }

        $currentVersion = $latestVersion->version_number;
        $versionParts = explode('.', $currentVersion);

        switch ($versionType) {
            case 'major':
                $versionParts[0] = (int)$versionParts[0] + 1;
                $versionParts[1] = 0;
                $versionParts[2] = 0;
                break;
            case 'minor':
                $versionParts[1] = (int)$versionParts[1] + 1;
                $versionParts[2] = 0;
                break;
            case 'patch':
                $versionParts[2] = (int)$versionParts[2] + 1;
                break;
            case 'beta':
                if (strpos($currentVersion, '-beta.') !== false) {
                    $betaVersion = (int)substr($currentVersion, strrpos($currentVersion, '.') + 1) + 1;
                    return $versionParts[0] . '.' . $versionParts[1] . '.' . $versionParts[2] . '-beta.' . $betaVersion;
                } else {
                    return $versionParts[0] . '.' . $versionParts[1] . '.' . $versionParts[2] . '-beta.1';
                }
            case 'alpha':
                if (strpos($currentVersion, '-alpha.') !== false) {
                    $alphaVersion = (int)substr($currentVersion, strrpos($currentVersion, '.') + 1) + 1;
                    return $versionParts[0] . '.' . $versionParts[1] . '.' . $versionParts[2] . '-alpha.' . $alphaVersion;
                } else {
                    return $versionParts[0] . '.' . $versionParts[1] . '.' . $versionParts[2] . '-alpha.1';
                }
        }

        return implode('.', $versionParts);
    }

    /**
     * Create version snapshot
     */
    private function createVersionSnapshot($course, $version)
    {
        $snapshot = [
            'course_data' => [
                'title' => $course->title,
                'description' => $course->description,
                'difficulty_level' => $course->difficulty_level,
                'estimated_duration' => $course->estimated_duration,
                'target_audience' => $course->target_audience,
                'learning_objectives' => $course->learning_objectives,
                'prerequisites' => $course->prerequisites,
                'price' => $course->price,
                'currency' => $course->currency,
                'metadata' => $course->metadata
            ],
            'sections' => []
        ];

        foreach ($course->sections as $section) {
            $sectionData = [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'order' => $section->order,
                'is_free' => $section->is_free,
                'metadata' => $section->metadata,
                'lessons' => []
            ];

            foreach ($section->lessons as $lesson) {
                $lessonData = [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'content_type' => $lesson->content_type,
                    'order' => $lesson->order,
                    'duration' => $lesson->duration,
                    'is_free' => $lesson->is_free,
                    'content' => $lesson->content,
                    'video_url' => $lesson->video_url,
                    'attachments' => $lesson->attachments,
                    'metadata' => $lesson->metadata
                ];

                $sectionData['lessons'][] = $lessonData;
            }

            $snapshot['sections'][] = $sectionData;
        }

        $version->snapshot = $snapshot;
        $version->save();
    }

    /**
     * Deactivate other versions
     */
    private function deactivateOtherVersions($courseId)
    {
        CourseVersion::where('course_id', $courseId)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * Get course versions
     */
    public function getCourseVersions($courseId, $filters = [])
    {
        $query = CourseVersion::where('course_id', $courseId);

        // Apply filters
        if (isset($filters['version_type'])) {
            $query->where('version_type', $filters['version_type']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['is_published'])) {
            $query->where('is_published', $filters['is_published']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('title', 'LIKE', "%{$filters['search']}%")
                  ->orWhere('description', 'LIKE', "%{$filters['search']}%");
            });
        }

        return $query->with('createdBy')
            ->orderBy('created_at', 'desc')
            ->paginate(20);
    }

    /**
     * Get version by ID
     */
    public function getVersion($versionId)
    {
        return CourseVersion::with(['course', 'createdBy'])->find($versionId);
    }

    /**
     * Update version
     */
    public function updateVersion($versionId, $data)
    {
        try {
            $version = CourseVersion::find($versionId);
            
            if (!$version) {
                return [
                    'success' => false,
                    'message' => 'Version not found'
                ];
            }

            // Update fields
            $updatableFields = [
                'title', 'description', 'change_log', 'changes_summary',
                'is_active', 'is_published', 'metadata'
            ];

            foreach ($updatableFields as $field) {
                if (isset($data[$field])) {
                    $version->$field = $data[$field];
                }
            }

            // Handle active status change
            if (isset($data['is_active']) && $data['is_active']) {
                $this->deactivateOtherVersions($version->course_id);
                
                // Update course current version
                $course = Course::find($version->course_id);
                $course->current_version_id = $version->id;
                $course->save();
            }

            $version->updated_at = now();
            $version->save();

            Log::info("Course version updated", [
                'version_id' => $versionId,
                'course_id' => $version->course_id
            ]);

            return [
                'success' => true,
                'version' => $version,
                'message' => 'Version updated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to update version: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update version'
            ];
        }
    }

    /**
     * Delete version
     */
    public function deleteVersion($versionId)
    {
        try {
            $version = CourseVersion::find($versionId);
            
            if (!$version) {
                return [
                    'success' => false,
                    'message' => 'Version not found'
                ];
            }

            // Check if version is active
            if ($version->is_active) {
                return [
                    'success' => false,
                    'message' => 'Cannot delete active version'
                ];
            }

            // Check if version has enrollments
            if ($this->versionHasEnrollments($version->id)) {
                return [
                    'success' => false,
                    'message' => 'Cannot delete version with active enrollments'
                ];
            }

            $version->delete();

            Log::info("Course version deleted", [
                'version_id' => $versionId,
                'course_id' => $version->course_id
            ]);

            return [
                'success' => true,
                'message' => 'Version deleted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to delete version: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete version'
            ];
        }
    }

    /**
     * Check if version has enrollments
     */
    private function versionHasEnrollments($versionId)
    {
        return CourseEnrollment::where('version_id', $versionId)->exists();
    }

    /**
     * Compare versions
     */
    public function compareVersions($courseId, $version1Id, $version2Id)
    {
        try {
            $version1 = CourseVersion::find($version1Id);
            $version2 = CourseVersion::find($version2Id);

            if (!$version1 || !$version2) {
                return [
                    'success' => false,
                    'message' => 'One or both versions not found'
                ];
            }

            if ($version1->course_id !== $courseId || $version2->course_id !== $courseId) {
                return [
                    'success' => false,
                    'message' => 'Versions do not belong to the specified course'
                ];
            }

            $comparison = [
                'version1' => [
                    'id' => $version1->id,
                    'version_number' => $version1->version_number,
                    'title' => $version1->title,
                    'created_at' => $version1->created_at
                ],
                'version2' => [
                    'id' => $version2->id,
                    'version_number' => $version2->version_number,
                    'title' => $version2->title,
                    'created_at' => $version2->created_at
                ],
                'differences' => $this->findDifferences($version1, $version2)
            ];

            return [
                'success' => true,
                'comparison' => $comparison
            ];

        } catch (\Exception $e) {
            Log::error("Failed to compare versions: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to compare versions'
            ];
        }
    }

    /**
     * Find differences between versions
     */
    private function findDifferences($version1, $version2)
    {
        $differences = [];

        $snapshot1 = $version1->snapshot;
        $snapshot2 = $version2->snapshot;

        // Compare course data
        $differences['course_data'] = $this->compareArrays($snapshot1['course_data'], $snapshot2['course_data']);

        // Compare sections
        $differences['sections'] = $this->compareSections($snapshot1['sections'], $snapshot2['sections']);

        return $differences;
    }

    /**
     * Compare arrays
     */
    private function compareArrays($array1, $array2)
    {
        $differences = [];

        foreach ($array1 as $key => $value1) {
            if (!isset($array2[$key])) {
                $differences[$key] = [
                    'type' => 'removed',
                    'old_value' => $value1,
                    'new_value' => null
                ];
            } elseif ($value1 !== $array2[$key]) {
                $differences[$key] = [
                    'type' => 'changed',
                    'old_value' => $value1,
                    'new_value' => $array2[$key]
                ];
            }
        }

        foreach ($array2 as $key => $value2) {
            if (!isset($array1[$key])) {
                $differences[$key] = [
                    'type' => 'added',
                    'old_value' => null,
                    'new_value' => $value2
                ];
            }
        }

        return $differences;
    }

    /**
     * Compare sections
     */
    private function compareSections($sections1, $sections2)
    {
        $differences = [];

        // Create maps for easy comparison
        $sections1Map = collect($sections1)->keyBy('id');
        $sections2Map = collect($sections2)->keyBy('id');

        // Find added, removed, and changed sections
        foreach ($sections1Map as $id => $section1) {
            if (!$sections2Map->has($id)) {
                $differences['sections'][$id] = [
                    'type' => 'removed',
                    'section' => $section1
                ];
            } else {
                $section2 = $sections2Map[$id];
                $sectionDiff = $this->compareSection($section1, $section2);
                if (!empty($sectionDiff)) {
                    $differences['sections'][$id] = [
                        'type' => 'changed',
                        'differences' => $sectionDiff
                    ];
                }
            }
        }

        foreach ($sections2Map as $id => $section2) {
            if (!$sections1Map->has($id)) {
                $differences['sections'][$id] = [
                    'type' => 'added',
                    'section' => $section2
                ];
            }
        }

        return $differences;
    }

    /**
     * Compare section
     */
    private function compareSection($section1, $section2)
    {
        $differences = [];

        // Compare section properties
        $sectionProps = ['title', 'description', 'order', 'is_free'];
        foreach ($sectionProps as $prop) {
            if ($section1[$prop] !== $section2[$prop]) {
                $differences[$prop] = [
                    'old_value' => $section1[$prop],
                    'new_value' => $section2[$prop]
                ];
            }
        }

        // Compare lessons
        $lessons1Map = collect($section1['lessons'])->keyBy('id');
        $lessons2Map = collect($section2['lessons'])->keyBy('id');

        foreach ($lessons1Map as $id => $lesson1) {
            if (!$lessons2Map->has($id)) {
                $differences['lessons'][$id] = [
                    'type' => 'removed',
                    'lesson' => $lesson1
                ];
            } else {
                $lesson2 = $lessons2Map[$id];
                $lessonDiff = $this->compareLesson($lesson1, $lesson2);
                if (!empty($lessonDiff)) {
                    $differences['lessons'][$id] = [
                        'type' => 'changed',
                        'differences' => $lessonDiff
                    ];
                }
            }
        }

        foreach ($lessons2Map as $id => $lesson2) {
            if (!$lessons1Map->has($id)) {
                $differences['lessons'][$id] = [
                    'type' => 'added',
                    'lesson' => $lesson2
                ];
            }
        }

        return $differences;
    }

    /**
     * Compare lesson
     */
    private function compareLesson($lesson1, $lesson2)
    {
        $differences = [];

        $lessonProps = ['title', 'description', 'content_type', 'order', 'duration', 'is_free'];
        foreach ($lessonProps as $prop) {
            if ($lesson1[$prop] !== $lesson2[$prop]) {
                $differences[$prop] = [
                    'old_value' => $lesson1[$prop],
                    'new_value' => $lesson2[$prop]
                ];
            }
        }

        return $differences;
    }

    /**
     * Rollback to version
     */
    public function rollbackToVersion($courseId, $versionId)
    {
        try {
            $version = CourseVersion::find($versionId);
            
            if (!$version) {
                return [
                    'success' => false,
                    'message' => 'Version not found'
                ];
            }

            if ($version->course_id !== $courseId) {
                return [
                    'success' => false,
                    'message' => 'Version does not belong to the specified course'
                ];
            }

            DB::beginTransaction();

            try {
                // Create backup of current course
                $currentCourse = Course::find($courseId);
                $this->createVersion($courseId, [
                    'version_type' => 'patch',
                    'title' => 'Backup before rollback',
                    'description' => 'Automatic backup created before rolling back to version ' . $version->version_number,
                    'created_by' => auth()->id(),
                    'is_active' => false
                ]);

                // Restore course from version snapshot
                $this->restoreCourseFromSnapshot($currentCourse, $version->snapshot);

                // Activate the target version
                $this->deactivateOtherVersions($courseId);
                $version->is_active = true;
                $version->save();

                // Update course current version
                $currentCourse->current_version_id = $version->id;
                $currentCourse->save();

                DB::commit();

                Log::info("Course rolled back to version", [
                    'course_id' => $courseId,
                    'version_id' => $versionId,
                    'version_number' => $version->version_number
                ]);

                return [
                    'success' => true,
                    'message' => 'Course rolled back to version ' . $version->version_number . ' successfully'
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error("Failed to rollback course: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to rollback course'
            ];
        }
    }

    /**
     * Restore course from snapshot
     */
    private function restoreCourseFromSnapshot($course, $snapshot)
    {
        // Restore course data
        $courseData = $snapshot['course_data'];
        foreach ($courseData as $key => $value) {
            if (in_array($key, ['title', 'description', 'difficulty_level', 'estimated_duration', 'target_audience', 'learning_objectives', 'prerequisites', 'price', 'currency'])) {
                $course->$key = $value;
            }
        }

        $course->save();

        // Restore sections and lessons
        $this->restoreSectionsAndLessons($course, $snapshot['sections']);
    }

    /**
     * Restore sections and lessons
     */
    private function restoreSectionsAndLessons($course, $sections)
    {
        // Delete existing sections and lessons
        $course->sections()->delete();

        // Restore from snapshot
        foreach ($sections as $sectionData) {
            $section = CourseSection::create([
                'course_id' => $course->id,
                'title' => $sectionData['title'],
                'description' => $sectionData['description'],
                'order' => $sectionData['order'],
                'is_free' => $sectionData['is_free'],
                'metadata' => $sectionData['metadata']
            ]);

            foreach ($sectionData['lessons'] as $lessonData) {
                CourseLesson::create([
                    'course_id' => $course->id,
                    'section_id' => $section->id,
                    'title' => $lessonData['title'],
                    'description' => $lessonData['description'],
                    'content_type' => $lessonData['content_type'],
                    'order' => $lessonData['order'],
                    'duration' => $lessonData['duration'],
                    'is_free' => $lessonData['is_free'],
                    'content' => $lessonData['content'],
                    'video_url' => $lessonData['video_url'],
                    'attachments' => $lessonData['attachments'],
                    'metadata' => $lessonData['metadata']
                ]);
            }
        }
    }

    /**
     * Get version types
     */
    public function getVersionTypes()
    {
        return $this->versionTypes;
    }

    /**
     * Get change types
     */
    public function getChangeTypes()
    {
        return $this->changeTypes;
    }

    /**
     * Get version statistics
     */
    public function getVersionStats($courseId = null)
    {
        $query = CourseVersion::query();

        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        $stats = [
            'total_versions' => $query->count(),
            'versions_by_type' => $query->selectRaw('version_type, COUNT(*) as count')
                ->groupBy('version_type')
                ->pluck('count', 'version_type'),
            'active_versions' => $query->where('is_active', true)->count(),
            'published_versions' => $query->where('is_published', true)->count(),
            'average_versions_per_course' => $query->selectRaw('course_id, COUNT(*) as version_count')
                ->groupBy('course_id')
                ->avg('version_count')
        ];

        return $stats;
    }
} 