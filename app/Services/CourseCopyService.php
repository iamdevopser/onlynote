<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\CourseLesson;
use App\Models\CourseEnrollment;
use App\Models\CourseReview;
use App\Models\CourseQuiz;

class CourseCopyService
{
    protected $copyOptions = [
        'copy_sections' => true,
        'copy_lessons' => true,
        'copy_quizzes' => true,
        'copy_assignments' => true,
        'copy_resources' => true,
        'copy_metadata' => true,
        'copy_files' => false,
        'reset_progress' => true,
        'reset_enrollments' => true,
        'reset_reviews' => true,
        'reset_analytics' => true
    ];

    /**
     * Copy course
     */
    public function copyCourse($courseId, $instructorId, $options = [])
    {
        try {
            $originalCourse = Course::with([
                'sections.lessons',
                'sections.lessons.quizzes',
                'sections.lessons.assignments',
                'sections.lessons.resources'
            ])->find($courseId);

            if (!$originalCourse) {
                return [
                    'success' => false,
                    'message' => 'Original course not found'
                ];
            }

            // Check if instructor has permission to copy
            if (!$this->canCopyCourse($originalCourse, $instructorId)) {
                return [
                    'success' => false,
                    'message' => 'You do not have permission to copy this course'
                ];
            }

            // Merge options with defaults
            $copyOptions = array_merge($this->copyOptions, $options);

            DB::beginTransaction();

            try {
                // Create new course
                $newCourse = $this->createCourseCopy($originalCourse, $instructorId, $copyOptions);

                // Copy course structure
                if ($copyOptions['copy_sections']) {
                    $this->copyCourseSections($originalCourse, $newCourse, $copyOptions);
                }

                // Copy course metadata
                if ($copyOptions['copy_metadata']) {
                    $this->copyCourseMetadata($originalCourse, $newCourse);
                }

                // Copy course files if requested
                if ($copyOptions['copy_files']) {
                    $this->copyCourseFiles($originalCourse, $newCourse);
                }

                DB::commit();

                Log::info("Course copied successfully", [
                    'original_course_id' => $courseId,
                    'new_course_id' => $newCourse->id,
                    'instructor_id' => $instructorId
                ]);

                return [
                    'success' => true,
                    'course' => $newCourse,
                    'message' => 'Course copied successfully',
                    'copy_details' => [
                        'sections_copied' => $copyOptions['copy_sections'] ? $newCourse->sections()->count() : 0,
                        'lessons_copied' => $copyOptions['copy_lessons'] ? $newCourse->sections()->withCount('lessons')->get()->sum('lessons_count') : 0,
                        'quizzes_copied' => $copyOptions['copy_quizzes'] ? $this->countCopiedQuizzes($newCourse) : 0,
                        'files_copied' => $copyOptions['copy_files']
                    ]
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error("Failed to copy course: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to copy course: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if instructor can copy course
     */
    private function canCopyCourse($course, $instructorId)
    {
        // Instructor can copy their own courses
        if ($course->instructor_id == $instructorId) {
            return true;
        }

        // Check if course is public and allows copying
        if ($course->is_public && isset($course->metadata['allow_copying']) && $course->metadata['allow_copying']) {
            return true;
        }

        // Check if instructor has admin role
        $instructor = User::find($instructorId);
        if ($instructor && $instructor->role === 'admin') {
            return true;
        }

        return false;
    }

    /**
     * Create course copy
     */
    private function createCourseCopy($originalCourse, $instructorId, $copyOptions)
    {
        $newCourseData = [
            'title' => $originalCourse->title . ' (Copy)',
            'description' => $originalCourse->description,
            'instructor_id' => $instructorId,
            'category_id' => $originalCourse->category_id,
            'difficulty_level' => $originalCourse->difficulty_level,
            'estimated_duration' => $originalCourse->estimated_duration,
            'target_audience' => $originalCourse->target_audience,
            'learning_objectives' => $originalCourse->learning_objectives,
            'prerequisites' => $originalCourse->prerequisites,
            'price' => $originalCourse->price,
            'currency' => $originalCourse->currency,
            'status' => 'draft',
            'metadata' => array_merge($originalCourse->metadata ?? [], [
                'copied_from' => $originalCourse->id,
                'copied_at' => now()->toISOString(),
                'copy_options' => $copyOptions
            ])
        ];

        return Course::create($newCourseData);
    }

    /**
     * Copy course sections
     */
    private function copyCourseSections($originalCourse, $newCourse, $copyOptions)
    {
        foreach ($originalCourse->sections as $section) {
            $newSection = CourseSection::create([
                'course_id' => $newCourse->id,
                'title' => $section->title,
                'description' => $section->description,
                'order' => $section->order,
                'is_free' => $section->is_free,
                'metadata' => $section->metadata
            ]);

            if ($copyOptions['copy_lessons'] && $section->lessons) {
                $this->copyCourseLessons($section, $newSection, $copyOptions);
            }
        }
    }

    /**
     * Copy course lessons
     */
    private function copyCourseLessons($originalSection, $newSection, $copyOptions)
    {
        foreach ($originalSection->lessons as $lesson) {
            $newLesson = CourseLesson::create([
                'course_id' => $newSection->course_id,
                'section_id' => $newSection->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'content_type' => $lesson->content_type,
                'order' => $lesson->order,
                'duration' => $lesson->duration,
                'is_free' => $lesson->is_free,
                'content' => $lesson->content,
                'video_url' => $lesson->video_url,
                'metadata' => $lesson->metadata
            ]);

            // Copy quizzes
            if ($copyOptions['copy_quizzes'] && $lesson->quizzes) {
                $this->copyLessonQuizzes($lesson, $newLesson);
            }

            // Copy assignments
            if ($copyOptions['copy_assignments'] && $lesson->assignments) {
                $this->copyLessonAssignments($lesson, $newLesson);
            }

            // Copy resources
            if ($copyOptions['copy_resources'] && $lesson->resources) {
                $this->copyLessonResources($lesson, $newLesson);
            }
        }
    }

    /**
     * Copy lesson quizzes
     */
    private function copyLessonQuizzes($originalLesson, $newLesson)
    {
        foreach ($originalLesson->quizzes as $quiz) {
            $newQuiz = CourseQuiz::create([
                'course_id' => $newLesson->course_id,
                'lesson_id' => $newLesson->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'quiz_type' => $quiz->quiz_type,
                'time_limit' => $quiz->time_limit,
                'passing_score' => $quiz->passing_score,
                'max_attempts' => $quiz->max_attempts,
                'is_active' => $quiz->is_active,
                'metadata' => $quiz->metadata
            ]);

            // Copy quiz questions
            if ($quiz->questions) {
                $this->copyQuizQuestions($quiz, $newQuiz);
            }
        }
    }

    /**
     * Copy quiz questions
     */
    private function copyQuizQuestions($originalQuiz, $newQuiz)
    {
        foreach ($originalQuiz->questions as $question) {
            $newQuestion = $newQuiz->questions()->create([
                'question_text' => $question->question_text,
                'question_type' => $question->question_type,
                'points' => $question->points,
                'order' => $question->order,
                'metadata' => $question->metadata
            ]);

            // Copy question options
            if ($question->options) {
                foreach ($question->options as $option) {
                    $newQuestion->options()->create([
                        'option_text' => $option->option_text,
                        'is_correct' => $option->is_correct,
                        'order' => $option->order
                    ]);
                }
            }
        }
    }

    /**
     * Copy lesson assignments
     */
    private function copyLessonAssignments($originalLesson, $newLesson)
    {
        foreach ($originalLesson->assignments as $assignment) {
            $newAssignment = $newLesson->assignments()->create([
                'title' => $assignment->title,
                'description' => $assignment->description,
                'due_date' => $assignment->due_date,
                'points' => $assignment->points,
                'submission_type' => $assignment->submission_type,
                'is_active' => $assignment->is_active,
                'metadata' => $assignment->metadata
            ]);

            // Copy assignment criteria
            if ($assignment->criteria) {
                foreach ($assignment->criteria as $criterion) {
                    $newAssignment->criteria()->create([
                        'criterion_text' => $criterion->criterion_text,
                        'points' => $criterion->points,
                        'order' => $criterion->order
                    ]);
                }
            }
        }
    }

    /**
     * Copy lesson resources
     */
    private function copyLessonResources($originalLesson, $newLesson)
    {
        foreach ($originalLesson->resources as $resource) {
            $newResource = $newLesson->resources()->create([
                'title' => $resource->title,
                'description' => $resource->description,
                'resource_type' => $resource->resource_type,
                'file_path' => $resource->file_path,
                'file_size' => $resource->file_size,
                'mime_type' => $resource->mime_type,
                'is_downloadable' => $resource->is_downloadable,
                'order' => $resource->order,
                'metadata' => $resource->metadata
            ]);
        }
    }

    /**
     * Copy course metadata
     */
    private function copyCourseMetadata($originalCourse, $newCourse)
    {
        $metadata = $originalCourse->metadata ?? [];
        
        // Remove sensitive metadata
        unset($metadata['enrollment_count']);
        unset($metadata['completion_count']);
        unset($metadata['average_rating']);
        unset($metadata['revenue']);
        unset($metadata['last_accessed']);
        
        // Add copy metadata
        $metadata['copied_from'] = $originalCourse->id;
        $metadata['copied_at'] = now()->toISOString();
        $metadata['is_copy'] = true;
        
        $newCourse->metadata = $metadata;
        $newCourse->save();
    }

    /**
     * Copy course files
     */
    private function copyCourseFiles($originalCourse, $newCourse)
    {
        try {
            $sourcePath = "courses/{$originalCourse->id}";
            $destinationPath = "courses/{$newCourse->id}";

            if (Storage::disk('public')->exists($sourcePath)) {
                // Copy directory recursively
                $this->copyDirectory($sourcePath, $destinationPath);
            }

            // Update file paths in lessons
            $this->updateFilePaths($newCourse, $originalCourse->id, $newCourse->id);

        } catch (\Exception $e) {
            Log::warning("Failed to copy course files: " . $e->getMessage());
        }
    }

    /**
     * Copy directory recursively
     */
    private function copyDirectory($source, $destination)
    {
        $files = Storage::disk('public')->files($source);
        $directories = Storage::disk('public')->directories($source);

        // Copy files
        foreach ($files as $file) {
            $newPath = str_replace($source, $destination, $file);
            $content = Storage::disk('public')->get($file);
            Storage::disk('public')->put($newPath, $content);
        }

        // Copy subdirectories
        foreach ($directories as $directory) {
            $newDir = str_replace($source, $destination, $directory);
            Storage::disk('public')->makeDirectory($newDir);
            $this->copyDirectory($directory, $newDir);
        }
    }

    /**
     * Update file paths after copying
     */
    private function updateFilePaths($course, $oldId, $newId)
    {
        $lessons = $course->sections()->with('lessons')->get()->pluck('lessons')->flatten();

        foreach ($lessons as $lesson) {
            if ($lesson->file_path) {
                $newPath = str_replace("courses/{$oldId}", "courses/{$newId}", $lesson->file_path);
                $lesson->file_path = $newPath;
                $lesson->save();
            }

            // Update resources
            foreach ($lesson->resources as $resource) {
                if ($resource->file_path) {
                    $newPath = str_replace("courses/{$oldId}", "courses/{$newId}", $resource->file_path);
                    $resource->file_path = $newPath;
                    $resource->save();
                }
            }
        }
    }

    /**
     * Count copied quizzes
     */
    private function countCopiedQuizzes($course)
    {
        return $course->sections()
            ->with('lessons.quizzes')
            ->get()
            ->pluck('lessons')
            ->flatten()
            ->pluck('quizzes')
            ->flatten()
            ->count();
    }

    /**
     * Bulk copy courses
     */
    public function bulkCopyCourses($courseIds, $instructorId, $options = [])
    {
        $results = [
            'success' => true,
            'copied_courses' => [],
            'failed_courses' => [],
            'total_attempted' => count($courseIds),
            'total_copied' => 0,
            'total_failed' => 0
        ];

        foreach ($courseIds as $courseId) {
            $copyResult = $this->copyCourse($courseId, $instructorId, $options);
            
            if ($copyResult['success']) {
                $results['copied_courses'][] = $copyResult['course'];
                $results['total_copied']++;
            } else {
                $results['failed_courses'][] = [
                    'course_id' => $courseId,
                    'error' => $copyResult['message']
                ];
                $results['total_failed']++;
            }
        }

        $results['success'] = $results['total_failed'] === 0;

        Log::info("Bulk course copy completed", [
            'instructor_id' => $instructorId,
            'total_attempted' => $results['total_attempted'],
            'total_copied' => $results['total_copied'],
            'total_failed' => $results['total_failed']
        ]);

        return $results;
    }

    /**
     * Get copy options
     */
    public function getCopyOptions()
    {
        return $this->copyOptions;
    }

    /**
     * Validate copy options
     */
    public function validateCopyOptions($options)
    {
        $validOptions = array_keys($this->copyOptions);
        $invalidOptions = array_diff(array_keys($options), $validOptions);

        if (!empty($invalidOptions)) {
            return [
                'valid' => false,
                'errors' => ['Invalid options: ' . implode(', ', $invalidOptions)]
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get copy history
     */
    public function getCopyHistory($courseId = null, $instructorId = null)
    {
        $query = Course::whereNotNull('metadata->copied_from');

        if ($courseId) {
            $query->where('metadata->copied_from', $courseId);
        }

        if ($instructorId) {
            $query->where('instructor_id', $instructorId);
        }

        return $query->with(['instructor', 'originalCourse'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);
    }

    /**
     * Get original course
     */
    public function getOriginalCourse($copiedCourseId)
    {
        $copiedCourse = Course::find($copiedCourseId);
        
        if (!$copiedCourse || !isset($copiedCourse->metadata['copied_from'])) {
            return null;
        }

        return Course::find($copiedCourse->metadata['copied_from']);
    }

    /**
     * Check if course is a copy
     */
    public function isCourseCopy($courseId)
    {
        $course = Course::find($courseId);
        return $course && isset($course->metadata['copied_from']);
    }

    /**
     * Get copy statistics
     */
    public function getCopyStats($instructorId = null)
    {
        $query = Course::whereNotNull('metadata->copied_from');

        if ($instructorId) {
            $query->where('instructor_id', $instructorId);
        }

        $stats = [
            'total_copies' => $query->count(),
            'copies_by_month' => $query->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count')
                ->groupBy('month')
                ->orderBy('month', 'desc')
                ->pluck('count', 'month'),
            'most_copied_courses' => Course::selectRaw('metadata->>"$.copied_from" as original_course_id, COUNT(*) as copy_count')
                ->whereNotNull('metadata->copied_from')
                ->groupBy('metadata->>"$.copied_from"')
                ->orderBy('copy_count', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    $originalCourse = Course::find($item->original_course_id);
                    return [
                        'original_course' => $originalCourse,
                        'copy_count' => $item->copy_count
                    ];
                }),
            'copy_success_rate' => $this->calculateCopySuccessRate($instructorId)
        ];

        return $stats;
    }

    /**
     * Calculate copy success rate
     */
    private function calculateCopySuccessRate($instructorId = null)
    {
        $query = Course::whereNotNull('metadata->copied_from');

        if ($instructorId) {
            $query->where('instructor_id', $instructorId);
        }

        $totalCopies = $query->count();
        $successfulCopies = $query->where('status', '!=', 'failed')->count();

        return $totalCopies > 0 ? round(($successfulCopies / $totalCopies) * 100, 2) : 0;
    }

    /**
     * Revert course copy
     */
    public function revertCourseCopy($courseId)
    {
        try {
            $copiedCourse = Course::find($courseId);
            
            if (!$copiedCourse || !$this->isCourseCopy($courseId)) {
                return [
                    'success' => false,
                    'message' => 'Course is not a copy or not found'
                ];
            }

            // Check if course has enrollments
            if ($copiedCourse->enrollments()->count() > 0) {
                return [
                    'success' => false,
                    'message' => 'Cannot revert course with active enrollments'
                ];
            }

            // Delete copied course
            $copiedCourse->delete();

            // Delete associated files
            $coursePath = "courses/{$courseId}";
            if (Storage::disk('public')->exists($coursePath)) {
                Storage::disk('public')->deleteDirectory($coursePath);
            }

            Log::info("Course copy reverted", [
                'course_id' => $courseId,
                'original_course_id' => $copiedCourse->metadata['copied_from']
            ]);

            return [
                'success' => true,
                'message' => 'Course copy reverted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to revert course copy: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to revert course copy'
            ];
        }
    }
} 