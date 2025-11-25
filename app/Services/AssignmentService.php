<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentCriterion;
use App\Models\AssignmentResource;
use App\Models\Course;
use App\Models\User;

class AssignmentService
{
    /**
     * Create a new assignment
     */
    public function createAssignment(array $data): Assignment
    {
        DB::beginTransaction();
        try {
            $assignment = Assignment::create([
                'course_id' => $data['course_id'],
                'lesson_id' => $data['lesson_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'],
                'instructions' => $data['instructions'],
                'due_date' => $data['due_date'] ?? null,
                'points' => $data['points'] ?? 100,
                'submission_type' => $data['submission_type'] ?? 'mixed',
                'max_file_size' => $data['max_file_size'] ?? 10, // MB
                'allowed_file_types' => $data['allowed_file_types'] ?? ['pdf', 'doc', 'docx', 'txt'],
                'is_active' => $data['is_active'] ?? true,
                'allow_late_submission' => $data['allow_late_submission'] ?? false,
                'late_submission_penalty' => $data['late_submission_penalty'] ?? 0,
                'requires_peer_review' => $data['requires_peer_review'] ?? false,
                'peer_review_deadline' => $data['peer_review_deadline'] ?? null,
                'metadata' => $data['metadata'] ?? []
            ]);

            // Create criteria if provided
            if (isset($data['criteria']) && is_array($data['criteria'])) {
                foreach ($data['criteria'] as $criterion) {
                    $assignment->criteria()->create([
                        'criterion_text' => $criterion['text'],
                        'points' => $criterion['points'],
                        'order' => $criterion['order'] ?? 1
                    ]);
                }
            }

            // Create resources if provided
            if (isset($data['resources']) && is_array($data['resources'])) {
                foreach ($data['resources'] as $resource) {
                    $assignment->resources()->create([
                        'title' => $resource['title'],
                        'description' => $resource['description'] ?? '',
                        'resource_type' => $resource['type'],
                        'file_path' => $resource['file_path'] ?? null,
                        'file_size' => $resource['file_size'] ?? 0,
                        'mime_type' => $resource['mime_type'] ?? null,
                        'is_downloadable' => $resource['is_downloadable'] ?? true,
                        'order' => $resource['order'] ?? 1,
                        'metadata' => $resource['metadata'] ?? []
                    ]);
                }
            }

            DB::commit();
            
            Log::info("Assignment created successfully", [
                'assignment_id' => $assignment->id,
                'course_id' => $assignment->course_id
            ]);

            return $assignment;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create assignment: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update an assignment
     */
    public function updateAssignment(Assignment $assignment, array $data): Assignment
    {
        DB::beginTransaction();
        try {
            $assignment->update($data);

            // Update criteria if provided
            if (isset($data['criteria']) && is_array($data['criteria'])) {
                // Remove existing criteria
                $assignment->criteria()->delete();
                
                // Create new criteria
                foreach ($data['criteria'] as $criterion) {
                    $assignment->criteria()->create([
                        'criterion_text' => $criterion['text'],
                        'points' => $criterion['points'],
                        'order' => $criterion['order'] ?? 1
                    ]);
                }
            }

            // Update resources if provided
            if (isset($data['resources']) && is_array($data['resources'])) {
                // Remove existing resources
                $assignment->resources()->delete();
                
                // Create new resources
                foreach ($data['resources'] as $resource) {
                    $assignment->resources()->create([
                        'title' => $resource['title'],
                        'description' => $resource['description'] ?? '',
                        'resource_type' => $resource['type'],
                        'file_path' => $resource['file_path'] ?? null,
                        'file_size' => $resource['file_size'] ?? 0,
                        'mime_type' => $resource['mime_type'] ?? null,
                        'is_downloadable' => $resource['is_downloadable'] ?? true,
                        'order' => $resource['order'] ?? 1,
                        'metadata' => $resource['metadata'] ?? []
                    ]);
                }
            }

            DB::commit();
            
            Log::info("Assignment updated successfully", [
                'assignment_id' => $assignment->id
            ]);

            return $assignment;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to update assignment: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete an assignment
     */
    public function deleteAssignment(Assignment $assignment): bool
    {
        try {
            // Check if assignment has submissions
            if ($assignment->submissions()->count() > 0) {
                throw new \Exception('Cannot delete assignment that has submissions. Deactivate it instead.');
            }

            // Delete criteria and resources
            $assignment->criteria()->delete();
            $assignment->resources()->delete();
            
            $assignment->delete();
            
            Log::info("Assignment deleted successfully", [
                'assignment_id' => $assignment->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to delete assignment: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Submit an assignment
     */
    public function submitAssignment(int $assignmentId, int $userId, array $data): AssignmentSubmission
    {
        DB::beginTransaction();
        try {
            $assignment = Assignment::findOrFail($assignmentId);
            
            // Check if user can submit
            if (!$assignment->canUserSubmit($userId)) {
                throw new \Exception('User cannot submit this assignment.');
            }

            // Check if assignment is active
            if (!$assignment->isActive()) {
                throw new \Exception('Assignment is not active.');
            }

            // Check if assignment is overdue
            $isLate = $assignment->isOverdue();
            if ($isLate && !$assignment->allowsLateSubmission()) {
                throw new \Exception('Assignment is overdue and late submission is not allowed.');
            }

            // Handle file uploads
            $submissionFiles = [];
            if (isset($data['files']) && is_array($data['files'])) {
                foreach ($data['files'] as $file) {
                    if ($file->isValid()) {
                        $path = $file->store('assignments/' . $assignmentId . '/' . $userId, 'public');
                        $submissionFiles[] = [
                            'name' => $file->getClientOriginalName(),
                            'path' => $path,
                            'size' => $file->getSize(),
                            'mime_type' => $file->getMimeType()
                        ];
                    }
                }
            }

            // Create submission
            $submission = AssignmentSubmission::create([
                'assignment_id' => $assignmentId,
                'user_id' => $userId,
                'submission_text' => $data['submission_text'] ?? null,
                'submission_files' => $submissionFiles,
                'submission_links' => $data['submission_links'] ?? [],
                'submitted_at' => now(),
                'status' => 'submitted',
                'max_score' => $assignment->points,
                'is_late' => $isLate,
                'late_penalty' => $isLate ? $assignment->late_submission_penalty : 0,
                'metadata' => $data['metadata'] ?? []
            ]);

            DB::commit();
            
            Log::info("Assignment submitted successfully", [
                'submission_id' => $submission->id,
                'assignment_id' => $assignmentId,
                'user_id' => $userId
            ]);

            return $submission;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to submit assignment: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Grade an assignment submission
     */
    public function gradeSubmission(int $submissionId, array $data): AssignmentSubmission
    {
        try {
            $submission = AssignmentSubmission::findOrFail($submissionId);
            
            $submission->update([
                'score' => $data['score'],
                'feedback' => $data['feedback'] ?? '',
                'status' => 'graded',
                'graded_by' => auth()->id(),
                'graded_at' => now()
            ]);

            // Calculate percentage
            $percentage = $submission->max_score > 0 ? 
                round(($submission->score / $submission->max_score) * 100, 2) : 0;
            
            $submission->update(['percentage' => $percentage]);

            Log::info("Assignment submission graded successfully", [
                'submission_id' => $submissionId,
                'score' => $data['score']
            ]);

            return $submission;

        } catch (\Exception $e) {
            Log::error("Failed to grade submission: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get assignments for a course
     */
    public function getCourseAssignments(int $courseId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = Assignment::with(['course', 'lesson', 'criteria', 'resources'])
            ->byCourse($courseId)
            ->active();

        // Apply filters
        if (isset($filters['lesson_id'])) {
            $query->byLesson($filters['lesson_id']);
        }

        if (isset($filters['status'])) {
            switch ($filters['status']) {
                case 'upcoming':
                    $query->upcoming();
                    break;
                case 'overdue':
                    $query->overdue();
                    break;
            }
        }

        return $query->orderBy('due_date', 'asc')->get();
    }

    /**
     * Get user assignments
     */
    public function getUserAssignments(int $userId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = Assignment::with(['course', 'lesson', 'submissions' => function($q) use ($userId) {
            $q->where('user_id', $userId);
        }])
        ->whereHas('course.enrollments', function($q) use ($userId) {
            $q->where('user_id', $userId);
        })
        ->active();

        // Apply filters
        if (isset($filters['course_id'])) {
            $query->byCourse($filters['course_id']);
        }

        if (isset($filters['status'])) {
            switch ($filters['status']) {
                case 'upcoming':
                    $query->upcoming();
                    break;
                case 'overdue':
                    $query->overdue();
                    break;
                case 'submitted':
                    $query->whereHas('submissions', function($q) use ($userId) {
                        $q->where('user_id', $userId);
                    });
                    break;
                case 'not_submitted':
                    $query->whereDoesntHave('submissions', function($q) use ($userId) {
                        $q->where('user_id', $userId);
                    });
                    break;
            }
        }

        return $query->orderBy('due_date', 'asc')->get();
    }

    /**
     * Get assignment statistics
     */
    public function getAssignmentStatistics(int $assignmentId): array
    {
        $assignment = Assignment::with(['submissions'])->findOrFail($assignmentId);
        
        $totalSubmissions = $assignment->submissions()->count();
        $gradedSubmissions = $assignment->submissions()->graded()->count();
        $lateSubmissions = $assignment->submissions()->late()->count();
        
        $averageScore = $assignment->submissions()->graded()->avg('score') ?? 0;
        $passingSubmissions = $assignment->submissions()->graded()
            ->where('percentage', '>=', 70)->count();
        
        return [
            'total_submissions' => $totalSubmissions,
            'graded_submissions' => $gradedSubmissions,
            'ungraded_submissions' => $totalSubmissions - $gradedSubmissions,
            'late_submissions' => $lateSubmissions,
            'average_score' => round($averageScore, 2),
            'passing_submissions' => $passingSubmissions,
            'passing_rate' => $gradedSubmissions > 0 ? 
                round(($passingSubmissions / $gradedSubmissions) * 100, 2) : 0
        ];
    }
}

