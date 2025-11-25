<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Discussion;
use App\Models\DiscussionReply;
use App\Models\DiscussionLike;
use App\Models\Course;
use App\Models\User;

class DiscussionService
{
    /**
     * Create a new discussion
     */
    public function createDiscussion(array $data): Discussion
    {
        DB::beginTransaction();
        try {
            $discussion = Discussion::create([
                'course_id' => $data['course_id'],
                'user_id' => Auth::id(),
                'title' => $data['title'],
                'content' => $data['content'],
                'type' => $data['type'] ?? 'general',
                'status' => 'open',
                'is_pinned' => false,
                'is_locked' => false,
                'view_count' => 0,
                'reply_count' => 0,
                'tags' => $data['tags'] ?? [],
                'metadata' => $data['metadata'] ?? []
            ]);

            DB::commit();
            
            Log::info("Discussion created successfully", [
                'discussion_id' => $discussion->id,
                'course_id' => $discussion->course_id,
                'user_id' => Auth::id()
            ]);

            return $discussion;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create discussion: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update a discussion
     */
    public function updateDiscussion(Discussion $discussion, array $data): Discussion
    {
        try {
            // Check if user can edit this discussion
            if ($discussion->user_id !== Auth::id() && !Auth::user()->hasRole(['admin', 'instructor'])) {
                throw new \Exception('You do not have permission to edit this discussion.');
            }

            $discussion->update($data);
            
            Log::info("Discussion updated successfully", [
                'discussion_id' => $discussion->id
            ]);

            return $discussion;

        } catch (\Exception $e) {
            Log::error("Failed to update discussion: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete a discussion
     */
    public function deleteDiscussion(Discussion $discussion): bool
    {
        try {
            // Check if user can delete this discussion
            if ($discussion->user_id !== Auth::id() && !Auth::user()->hasRole(['admin', 'instructor'])) {
                throw new \Exception('You do not have permission to delete this discussion.');
            }

            // Delete all related data
            $discussion->replies()->delete();
            $discussion->likes()->delete();
            $discussion->delete();
            
            Log::info("Discussion deleted successfully", [
                'discussion_id' => $discussion->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to delete discussion: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add a reply to a discussion
     */
    public function addReply(int $discussionId, array $data): DiscussionReply
    {
        DB::beginTransaction();
        try {
            $discussion = Discussion::findOrFail($discussionId);
            
            // Check if discussion is locked
            if ($discussion->isLocked()) {
                throw new \Exception('This discussion is locked and cannot receive new replies.');
            }

            $reply = DiscussionReply::create([
                'discussion_id' => $discussionId,
                'user_id' => Auth::id(),
                'parent_id' => $data['parent_id'] ?? null,
                'content' => $data['content'],
                'is_solution' => false,
                'is_edited' => false,
                'metadata' => $data['metadata'] ?? []
            ]);

            // Update discussion reply count and last reply info
            $discussion->updateReplyCount();

            DB::commit();
            
            Log::info("Reply added successfully", [
                'reply_id' => $reply->id,
                'discussion_id' => $discussionId,
                'user_id' => Auth::id()
            ]);

            return $reply;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to add reply: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update a reply
     */
    public function updateReply(DiscussionReply $reply, array $data): DiscussionReply
    {
        try {
            // Check if user can edit this reply
            if ($reply->user_id !== Auth::id() && !Auth::user()->hasRole(['admin', 'instructor'])) {
                throw new \Exception('You do not have permission to edit this reply.');
            }

            $reply->update([
                'content' => $data['content'],
                'is_edited' => true,
                'edited_at' => now(),
                'edited_by' => Auth::id(),
                'metadata' => $data['metadata'] ?? $reply->metadata
            ]);
            
            Log::info("Reply updated successfully", [
                'reply_id' => $reply->id
            ]);

            return $reply;

        } catch (\Exception $e) {
            Log::error("Failed to update reply: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete a reply
     */
    public function deleteReply(DiscussionReply $reply): bool
    {
        try {
            // Check if user can delete this reply
            if ($reply->user_id !== Auth::id() && !Auth::user()->hasRole(['admin', 'instructor'])) {
                throw new \Exception('You do not have permission to delete this reply.');
            }

            $discussion = $reply->discussion;
            
            // Delete nested replies first
            $reply->replies()->delete();
            $reply->delete();
            
            // Update discussion reply count
            $discussion->updateReplyCount();
            
            Log::info("Reply deleted successfully", [
                'reply_id' => $reply->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to delete reply: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mark a reply as solution
     */
    public function markAsSolution(DiscussionReply $reply): bool
    {
        try {
            // Check if user can mark solution (discussion owner or instructor)
            $discussion = $reply->discussion;
            if ($discussion->user_id !== Auth::id() && !Auth::user()->hasRole(['admin', 'instructor'])) {
                throw new \Exception('You do not have permission to mark this reply as solution.');
            }

            $reply->markAsSolution();
            
            Log::info("Reply marked as solution", [
                'reply_id' => $reply->id,
                'discussion_id' => $discussion->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to mark reply as solution: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Toggle like on discussion or reply
     */
    public function toggleLike(string $type, int $targetId, string $likeType = 'like'): array
    {
        try {
            $userId = Auth::id();
            $like = null;
            
            if ($type === 'discussion') {
                $like = DiscussionLike::where('user_id', $userId)
                    ->where('discussion_id', $targetId)
                    ->where('reply_id', null)
                    ->first();
            } elseif ($type === 'reply') {
                $like = DiscussionLike::where('user_id', $userId)
                    ->where('discussion_id', null)
                    ->where('reply_id', $targetId)
                    ->first();
            }

            if ($like) {
                // Remove existing like
                $like->delete();
                $action = 'removed';
            } else {
                // Add new like
                $like = DiscussionLike::create([
                    'user_id' => $userId,
                    'discussion_id' => $type === 'discussion' ? $targetId : null,
                    'reply_id' => $type === 'reply' ? $targetId : null,
                    'type' => $likeType,
                    'metadata' => []
                ]);
                $action = 'added';
            }

            Log::info("Like toggled successfully", [
                'type' => $type,
                'target_id' => $targetId,
                'like_type' => $likeType,
                'action' => $action,
                'user_id' => $userId
            ]);

            return [
                'action' => $action,
                'like' => $like
            ];

        } catch (\Exception $e) {
            Log::error("Failed to toggle like: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get discussions for a course
     */
    public function getCourseDiscussions(int $courseId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = Discussion::with(['user', 'lastReplier', 'replies'])
            ->byCourse($courseId);

        // Apply filters
        if (isset($filters['type'])) {
            $query->byType($filters['type']);
        }

        if (isset($filters['status'])) {
            if ($filters['status'] === 'open') {
                $query->open();
            } elseif ($filters['status'] === 'closed') {
                $query->where('status', 'closed');
            }
        }

        if (isset($filters['sort'])) {
            switch ($filters['sort']) {
                case 'popular':
                    $query->popular();
                    break;
                case 'recent':
                    $query->recent();
                    break;
                case 'pinned':
                    $query->pinned();
                    break;
            }
        }

        return $query->orderBy('is_pinned', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get discussion with replies
     */
    public function getDiscussionWithReplies(int $discussionId): Discussion
    {
        $discussion = Discussion::with([
            'user',
            'replies.user',
            'replies.replies.user',
            'likes'
        ])->findOrFail($discussionId);

        // Increment view count
        $discussion->incrementViewCount();

        return $discussion;
    }

    /**
     * Search discussions
     */
    public function searchDiscussions(string $query, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $searchQuery = Discussion::with(['user', 'course'])
            ->where(function($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('content', 'like', "%{$query}%");
            });

        // Apply filters
        if (isset($filters['course_id'])) {
            $searchQuery->byCourse($filters['course_id']);
        }

        if (isset($filters['type'])) {
            $searchQuery->byType($filters['type']);
        }

        return $searchQuery->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get discussion statistics
     */
    public function getDiscussionStatistics(int $courseId): array
    {
        $totalDiscussions = Discussion::byCourse($courseId)->count();
        $openDiscussions = Discussion::byCourse($courseId)->open()->count();
        $closedDiscussions = Discussion::byCourse($courseId)->where('status', 'closed')->count();
        $pinnedDiscussions = Discussion::byCourse($courseId)->pinned()->count();
        
        $totalReplies = DiscussionReply::whereHas('discussion', function($q) use ($courseId) {
            $q->where('course_id', $courseId);
        })->count();
        
        $totalLikes = DiscussionLike::whereHas('discussion', function($q) use ($courseId) {
            $q->where('course_id', $courseId);
        })->count();

        return [
            'total_discussions' => $totalDiscussions,
            'open_discussions' => $openDiscussions,
            'closed_discussions' => $closedDiscussions,
            'pinned_discussions' => $pinnedDiscussions,
            'total_replies' => $totalReplies,
            'total_likes' => $totalLikes,
            'average_replies_per_discussion' => $totalDiscussions > 0 ? 
                round($totalReplies / $totalDiscussions, 2) : 0
        ];
    }
}










