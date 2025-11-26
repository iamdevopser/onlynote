<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\LiveClass;
use App\Models\LiveClassParticipant;
use App\Models\LiveClassChatMessage;
use App\Models\LiveClassPoll;
use App\Models\Course;
use App\Models\User;

class LiveClassService
{
    /**
     * Create a new live class
     */
    public function createLiveClass(array $data): LiveClass
    {
        DB::beginTransaction();
        try {
            // Calculate end time if duration is provided
            if (isset($data['start_time']) && isset($data['duration'])) {
                $startTime = \Carbon\Carbon::parse($data['start_time']);
                $data['end_time'] = $startTime->addMinutes($data['duration']);
            }

            $liveClass = LiveClass::create([
                'course_id' => $data['course_id'],
                'instructor_id' => Auth::id(),
                'title' => $data['title'],
                'description' => $data['description'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'] ?? null,
                'duration' => $data['duration'] ?? 60,
                'max_participants' => $data['max_participants'] ?? 50,
                'current_participants' => 0,
                'status' => 'scheduled',
                'meeting_url' => $data['meeting_url'] ?? null,
                'meeting_id' => $data['meeting_id'] ?? null,
                'meeting_password' => $data['meeting_password'] ?? null,
                'is_recording_enabled' => $data['is_recording_enabled'] ?? true,
                'is_chat_enabled' => $data['is_chat_enabled'] ?? true,
                'is_screen_sharing_enabled' => $data['is_screen_sharing_enabled'] ?? true,
                'is_polling_enabled' => $data['is_polling_enabled'] ?? true,
                'is_whiteboard_enabled' => $data['is_whiteboard_enabled'] ?? true,
                'metadata' => $data['metadata'] ?? []
            ]);

            DB::commit();
            
            Log::info("Live class created successfully", [
                'live_class_id' => $liveClass->id,
                'course_id' => $liveClass->course_id,
                'instructor_id' => Auth::id()
            ]);

            return $liveClass;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create live class: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update a live class
     */
    public function updateLiveClass(LiveClass $liveClass, array $data): LiveClass
    {
        try {
            // Check if user can edit this live class
            if ($liveClass->instructor_id !== Auth::id() && !Auth::user()->hasRole(['admin'])) {
                throw new \Exception('You do not have permission to edit this live class.');
            }

            // Recalculate end time if start_time or duration changed
            if (isset($data['start_time']) || isset($data['duration'])) {
                $startTime = $data['start_time'] ?? $liveClass->start_time;
                $duration = $data['duration'] ?? $liveClass->duration;
                $data['end_time'] = \Carbon\Carbon::parse($startTime)->addMinutes($duration);
            }

            $liveClass->update($data);
            
            Log::info("Live class updated successfully", [
                'live_class_id' => $liveClass->id
            ]);

            return $liveClass;

        } catch (\Exception $e) {
            Log::error("Failed to update live class: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete a live class
     */
    public function deleteLiveClass(LiveClass $liveClass): bool
    {
        try {
            // Check if user can delete this live class
            if ($liveClass->instructor_id !== Auth::id() && !Auth::user()->hasRole(['admin'])) {
                throw new \Exception('You do not have permission to delete this live class.');
            }

            // Check if class is already live or has participants
            if ($liveClass->isLive() || $liveClass->participants()->count() > 0) {
                throw new \Exception('Cannot delete live class that is currently active or has participants.');
            }

            $liveClass->delete();
            
            Log::info("Live class deleted successfully", [
                'live_class_id' => $liveClass->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to delete live class: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Start a live class
     */
    public function startLiveClass(LiveClass $liveClass): LiveClass
    {
        try {
            // Check if user can start this live class
            if ($liveClass->instructor_id !== Auth::id() && !Auth::user()->hasRole(['admin'])) {
                throw new \Exception('You do not have permission to start this live class.');
            }

            // Check if class is scheduled
            if (!$liveClass->isScheduled()) {
                throw new \Exception('Only scheduled classes can be started.');
            }

            $liveClass->update([
                'status' => 'live',
                'start_time' => now()
            ]);
            
            Log::info("Live class started successfully", [
                'live_class_id' => $liveClass->id
            ]);

            return $liveClass;

        } catch (\Exception $e) {
            Log::error("Failed to start live class: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * End a live class
     */
    public function endLiveClass(LiveClass $liveClass): LiveClass
    {
        try {
            // Check if user can end this live class
            if ($liveClass->instructor_id !== Auth::id() && !Auth::user()->hasRole(['admin'])) {
                throw new \Exception('You do not have permission to end this live class.');
            }

            // Check if class is live
            if (!$liveClass->isLive()) {
                throw new \Exception('Only live classes can be ended.');
            }

            $liveClass->update([
                'status' => 'ended',
                'end_time' => now()
            ]);
            
            Log::info("Live class ended successfully", [
                'live_class_id' => $liveClass->id
            ]);

            return $liveClass;

        } catch (\Exception $e) {
            Log::error("Failed to end live class: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cancel a live class
     */
    public function cancelLiveClass(LiveClass $liveClass, string $reason = ''): LiveClass
    {
        try {
            // Check if user can cancel this live class
            if ($liveClass->instructor_id !== Auth::id() && !Auth::user()->hasRole(['admin'])) {
                throw new \Exception('You do not have permission to cancel this live class.');
            }

            // Check if class is not already ended or cancelled
            if ($liveClass->hasEnded() || $liveClass->isCancelled()) {
                throw new \Exception('Cannot cancel a class that has already ended or been cancelled.');
            }

            $liveClass->update([
                'status' => 'cancelled',
                'metadata' => array_merge($liveClass->metadata ?? [], [
                    'cancellation_reason' => $reason,
                    'cancelled_at' => now()->toISOString(),
                    'cancelled_by' => Auth::id()
                ])
            ]);
            
            Log::info("Live class cancelled successfully", [
                'live_class_id' => $liveClass->id,
                'reason' => $reason
            ]);

            return $liveClass;

        } catch (\Exception $e) {
            Log::error("Failed to cancel live class: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Join a live class
     */
    public function joinLiveClass(LiveClass $liveClass, int $userId): LiveClassParticipant
    {
        DB::beginTransaction();
        try {
            // Check if user can join
            if (!$liveClass->canUserJoin($userId)) {
                throw new \Exception('User cannot join this live class.');
            }

            // Check if user is already a participant
            $existingParticipant = $liveClass->participants()->where('user_id', $userId)->first();
            if ($existingParticipant) {
                // Update join time if rejoining
                $existingParticipant->update(['joined_at' => now()]);
                DB::commit();
                return $existingParticipant;
            }

            // Create new participant
            $participant = LiveClassParticipant::create([
                'live_class_id' => $liveClass->id,
                'user_id' => $userId,
                'joined_at' => now(),
                'status' => 'active'
            ]);

            // Increment participant count
            $liveClass->increment('current_participants');

            DB::commit();
            
            Log::info("User joined live class successfully", [
                'live_class_id' => $liveClass->id,
                'user_id' => $userId
            ]);

            return $participant;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to join live class: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Leave a live class
     */
    public function leaveLiveClass(LiveClass $liveClass, int $userId): bool
    {
        try {
            $participant = $liveClass->participants()->where('user_id', $userId)->first();
            
            if (!$participant) {
                throw new \Exception('User is not a participant in this live class.');
            }

            $participant->update([
                'left_at' => now(),
                'status' => 'left'
            ]);

            // Decrement participant count
            $liveClass->decrement('current_participants');
            
            Log::info("User left live class successfully", [
                'live_class_id' => $liveClass->id,
                'user_id' => $userId
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to leave live class: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send chat message
     */
    public function sendChatMessage(int $liveClassId, array $data): LiveClassChatMessage
    {
        try {
            $liveClass = LiveClass::findOrFail($liveClassId);
            
            // Check if chat is enabled
            if (!$liveClass->is_chat_enabled) {
                throw new \Exception('Chat is disabled for this live class.');
            }

            // Check if user is a participant
            if (!$liveClass->participants()->where('user_id', Auth::id())->exists()) {
                throw new \Exception('You must be a participant to send chat messages.');
            }

            $message = LiveClassChatMessage::create([
                'live_class_id' => $liveClassId,
                'user_id' => Auth::id(),
                'message' => $data['message'],
                'message_type' => $data['message_type'] ?? 'text',
                'is_private' => $data['is_private'] ?? false,
                'recipient_id' => $data['recipient_id'] ?? null,
                'metadata' => $data['metadata'] ?? []
            ]);
            
            Log::info("Chat message sent successfully", [
                'message_id' => $message->id,
                'live_class_id' => $liveClassId,
                'user_id' => Auth::id()
            ]);

            return $message;

        } catch (\Exception $e) {
            Log::error("Failed to send chat message: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get live classes for a course
     */
    public function getCourseLiveClasses(int $courseId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = LiveClass::with(['instructor', 'participants'])
            ->byCourse($courseId);

        // Apply filters
        if (isset($filters['status'])) {
            switch ($filters['status']) {
                case 'scheduled':
                    $query->scheduled();
                    break;
                case 'live':
                    $query->live();
                    break;
                case 'ended':
                    $query->ended();
                    break;
            }
        }

        if (isset($filters['instructor_id'])) {
            $query->byInstructor($filters['instructor_id']);
        }

        return $query->orderBy('start_time', 'asc')->get();
    }

    /**
     * Get user's live classes
     */
    public function getUserLiveClasses(int $userId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = LiveClass::with(['course', 'instructor'])
            ->whereHas('course.enrollments', function($q) use ($userId) {
                $q->where('user_id', $userId);
            });

        // Apply filters
        if (isset($filters['status'])) {
            switch ($filters['status']) {
                case 'scheduled':
                    $query->scheduled();
                    break;
                case 'live':
                    $query->live();
                    break;
                case 'ended':
                    $query->ended();
                    break;
            }
        }

        if (isset($filters['course_id'])) {
            $query->byCourse($filters['course_id']);
        }

        return $query->orderBy('start_time', 'asc')->get();
    }

    /**
     * Get live class statistics
     */
    public function getLiveClassStatistics(int $courseId): array
    {
        $totalClasses = LiveClass::byCourse($courseId)->count();
        $scheduledClasses = LiveClass::byCourse($courseId)->scheduled()->count();
        $liveClasses = LiveClass::byCourse($courseId)->live()->count();
        $endedClasses = LiveClass::byCourse($courseId)->ended()->count();
        $cancelledClasses = LiveClass::byCourse($courseId)->where('status', 'cancelled')->count();
        
        $totalParticipants = LiveClassParticipant::whereHas('liveClass', function($q) use ($courseId) {
            $q->where('course_id', $courseId);
        })->count();
        
        $averageParticipants = $totalClasses > 0 ? round($totalParticipants / $totalClasses, 2) : 0;

        return [
            'total_classes' => $totalClasses,
            'scheduled_classes' => $scheduledClasses,
            'live_classes' => $liveClasses,
            'ended_classes' => $endedClasses,
            'cancelled_classes' => $cancelledClasses,
            'total_participants' => $totalParticipants,
            'average_participants_per_class' => $averageParticipants
        ];
    }
}










