<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChatRoom;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ChatService
{
    protected $redis;
    
    public function __construct()
    {
        $this->redis = Cache::store('redis');
    }

    /**
     * Create or get chat room between users
     */
    public function getOrCreateChatRoom($user1Id, $user2Id)
    {
        // Check if chat room already exists
        $existingRoom = ChatRoom::whereHas('participants', function ($query) use ($user1Id) {
            $query->where('user_id', $user1Id);
        })->whereHas('participants', function ($query) use ($user2Id) {
            $query->where('user_id', $user2Id);
        })->where('type', 'private')->first();

        if ($existingRoom) {
            return $existingRoom;
        }

        // Create new private chat room
        $room = ChatRoom::create([
            'name' => 'Private Chat',
            'type' => 'private',
            'created_by' => $user1Id
        ]);

        // Add participants
        $room->participants()->createMany([
            ['user_id' => $user1Id, 'role' => 'participant'],
            ['user_id' => $user2Id, 'role' => 'participant']
        ]);

        return $room;
    }

    /**
     * Create group chat room
     */
    public function createGroupChatRoom($name, $creatorId, $participantIds, $description = null)
    {
        $room = ChatRoom::create([
            'name' => $name,
            'description' => $description,
            'type' => 'group',
            'created_by' => $creatorId
        ]);

        // Add creator as admin
        $room->participants()->create([
            'user_id' => $creatorId,
            'role' => 'admin'
        ]);

        // Add other participants
        foreach ($participantIds as $participantId) {
            if ($participantId !== $creatorId) {
                $room->participants()->create([
                    'user_id' => $participantId,
                    'role' => 'participant'
                ]);
            }
        }

        return $room;
    }

    /**
     * Send message to chat room
     */
    public function sendMessage($roomId, $userId, $message, $type = 'text', $attachments = [])
    {
        $room = ChatRoom::findOrFail($roomId);
        
        // Check if user is participant
        $participant = $room->participants()->where('user_id', $userId)->first();
        if (!$participant) {
            throw new \Exception('User is not a participant in this chat room');
        }

        // Create message
        $chatMessage = ChatMessage::create([
            'room_id' => $roomId,
            'user_id' => $userId,
            'message' => $message,
            'type' => $type,
            'attachments' => $attachments
        ]);

        // Update room last activity
        $room->update(['last_activity_at' => now()]);

        // Mark message as read for sender
        $this->markMessageAsRead($roomId, $userId, $chatMessage->id);

        // Broadcast message to other participants
        $this->broadcastMessage($room, $chatMessage);

        // Update unread count for other participants
        $this->updateUnreadCounts($room, $userId);

        return $chatMessage;
    }

    /**
     * Get chat room messages
     */
    public function getRoomMessages($roomId, $userId, $limit = 50, $beforeId = null)
    {
        $room = ChatRoom::findOrFail($roomId);
        
        // Check if user is participant
        $participant = $room->participants()->where('user_id', $userId)->first();
        if (!$participant) {
            throw new \Exception('User is not a participant in this chat room');
        }

        $query = ChatMessage::where('room_id', $roomId)
            ->with(['user:id,name,avatar'])
            ->orderBy('created_at', 'desc');

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        $messages = $query->limit($limit)->get()->reverse();

        // Mark messages as read
        $this->markMessagesAsRead($roomId, $userId, $messages->pluck('id')->toArray());

        return $messages;
    }

    /**
     * Get user's chat rooms
     */
    public function getUserChatRooms($userId)
    {
        return ChatRoom::whereHas('participants', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->with(['participants.user:id,name,avatar', 'lastMessage.user:id,name'])
        ->orderBy('last_activity_at', 'desc')
        ->get()
        ->map(function ($room) use ($userId) {
            $room->unread_count = $this->getUnreadCount($room->id, $userId);
            $room->other_participants = $room->participants
                ->where('user_id', '!=', $userId)
                ->pluck('user');
            return $room;
        });
    }

    /**
     * Mark message as read
     */
    public function markMessageAsRead($roomId, $userId, $messageId)
    {
        $participant = ChatParticipant::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->first();

        if ($participant) {
            $participant->update([
                'last_read_message_id' => $messageId,
                'last_read_at' => now()
            ]);
        }
    }

    /**
     * Mark multiple messages as read
     */
    public function markMessagesAsRead($roomId, $userId, $messageIds)
    {
        if (empty($messageIds)) {
            return;
        }

        $lastMessageId = max($messageIds);
        $this->markMessageAsRead($roomId, $userId, $lastMessageId);
    }

    /**
     * Get unread message count
     */
    public function getUnreadCount($roomId, $userId)
    {
        $participant = ChatParticipant::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->first();

        if (!$participant || !$participant->last_read_message_id) {
            return ChatMessage::where('room_id', $roomId)->count();
        }

        return ChatMessage::where('room_id', $roomId)
            ->where('id', '>', $participant->last_read_message_id)
            ->count();
    }

    /**
     * Update unread counts for other participants
     */
    private function updateUnreadCounts($room, $excludeUserId)
    {
        $room->participants()
            ->where('user_id', '!=', $excludeUserId)
            ->update(['unread_count' => DB::raw('unread_count + 1')]);
    }

    /**
     * Broadcast message to other participants
     */
    private function broadcastMessage($room, $message)
    {
        $participants = $room->participants()
            ->where('user_id', '!=', $message->user_id)
            ->pluck('user_id');

        foreach ($participants as $participantId) {
            $this->redis->publish("chat.room.{$room->id}", json_encode([
                'type' => 'new_message',
                'message' => $message->load('user:id,name,avatar'),
                'room_id' => $room->id
            ]));
        }
    }

    /**
     * Add participant to group chat
     */
    public function addParticipantToGroup($roomId, $userId, $addedByUserId)
    {
        $room = ChatRoom::findOrFail($roomId);
        
        // Check if user has permission to add participants
        $adminParticipant = $room->participants()
            ->where('user_id', $addedByUserId)
            ->where('role', 'admin')
            ->first();

        if (!$adminParticipant) {
            throw new \Exception('Only admins can add participants to group chats');
        }

        // Check if user is already a participant
        $existingParticipant = $room->participants()
            ->where('user_id', $userId)
            ->first();

        if ($existingParticipant) {
            throw new \Exception('User is already a participant in this chat room');
        }

        // Add participant
        $room->participants()->create([
            'user_id' => $userId,
            'role' => 'participant'
        ]);

        // Send system message
        $this->sendSystemMessage($roomId, "User added to the group");

        return true;
    }

    /**
     * Remove participant from group chat
     */
    public function removeParticipantFromGroup($roomId, $userId, $removedByUserId)
    {
        $room = ChatRoom::findOrFail($roomId);
        
        // Check if user has permission to remove participants
        $adminParticipant = $room->participants()
            ->where('user_id', $removedByUserId)
            ->where('role', 'admin')
            ->first();

        if (!$adminParticipant) {
            throw new \Exception('Only admins can remove participants from group chats');
        }

        // Check if user is trying to remove themselves
        if ($userId === $removedByUserId) {
            throw new \Exception('Cannot remove yourself from the group');
        }

        // Remove participant
        $room->participants()->where('user_id', $userId)->delete();

        // Send system message
        $this->sendSystemMessage($roomId, "User removed from the group");

        return true;
    }

    /**
     * Send system message
     */
    public function sendSystemMessage($roomId, $message)
    {
        return ChatMessage::create([
            'room_id' => $roomId,
            'user_id' => null, // System message
            'message' => $message,
            'type' => 'system'
        ]);
    }

    /**
     * Search messages in chat room
     */
    public function searchMessages($roomId, $userId, $query, $limit = 20)
    {
        $room = ChatRoom::findOrFail($roomId);
        
        // Check if user is participant
        $participant = $room->participants()->where('user_id', $userId)->first();
        if (!$participant) {
            throw new \Exception('User is not a participant in this chat room');
        }

        return ChatMessage::where('room_id', $roomId)
            ->where('message', 'like', "%{$query}%")
            ->with(['user:id,name,avatar'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Delete message
     */
    public function deleteMessage($messageId, $userId)
    {
        $message = ChatMessage::findOrFail($messageId);
        
        // Check if user can delete message (owner or admin)
        if ($message->user_id !== $userId) {
            $participant = ChatParticipant::where('room_id', $message->room_id)
                ->where('user_id', $userId)
                ->where('role', 'admin')
                ->first();

            if (!$participant) {
                throw new \Exception('You can only delete your own messages');
            }
        }

        $message->delete();

        return true;
    }

    /**
     * Edit message
     */
    public function editMessage($messageId, $userId, $newMessage)
    {
        $message = ChatMessage::findOrFail($messageId);
        
        // Check if user owns the message
        if ($message->user_id !== $userId) {
            throw new \Exception('You can only edit your own messages');
        }

        // Check if message is not too old (e.g., 5 minutes)
        if ($message->created_at->diffInMinutes(now()) > 5) {
            throw new \Exception('Messages can only be edited within 5 minutes');
        }

        $message->update([
            'message' => $newMessage,
            'edited_at' => now()
        ]);

        return $message;
    }

    /**
     * Get chat statistics
     */
    public function getChatStats($period = '24h')
    {
        $startTime = match($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay()
        };

        $stats = [
            'total_messages' => ChatMessage::where('created_at', '>=', $startTime)->count(),
            'total_rooms' => ChatRoom::where('created_at', '>=', $startTime)->count(),
            'active_users' => ChatParticipant::where('last_read_at', '>=', $startTime)
                ->distinct('user_id')
                ->count(),
            'message_types' => ChatMessage::where('created_at', '>=', $startTime)
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->get()
                ->keyBy('type'),
            'period' => $period,
            'start_time' => $startTime,
            'end_time' => now()
        ];

        return $stats;
    }

    /**
     * Get user's chat activity
     */
    public function getUserChatActivity($userId, $period = '7d')
    {
        $startTime = match($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subWeek()
        };

        $activity = [
            'messages_sent' => ChatMessage::where('user_id', $userId)
                ->where('created_at', '>=', $startTime)
                ->count(),
            'rooms_active' => ChatParticipant::where('user_id', $userId)
                ->where('last_read_at', '>=', $startTime)
                ->count(),
            'last_activity' => ChatMessage::where('user_id', $userId)
                ->latest()
                ->first()?->created_at,
            'favorite_rooms' => ChatParticipant::where('user_id', $userId)
                ->with('room')
                ->orderBy('message_count', 'desc')
                ->limit(5)
                ->get()
                ->pluck('room')
        ];

        return $activity;
    }

    /**
     * Archive chat room
     */
    public function archiveChatRoom($roomId, $userId)
    {
        $room = ChatRoom::findOrFail($roomId);
        
        // Check if user is admin
        $adminParticipant = $room->participants()
            ->where('user_id', $userId)
            ->where('role', 'admin')
            ->first();

        if (!$adminParticipant) {
            throw new \Exception('Only admins can archive chat rooms');
        }

        $room->update(['archived_at' => now()]);

        return true;
    }

    /**
     * Restore archived chat room
     */
    public function restoreChatRoom($roomId, $userId)
    {
        $room = ChatRoom::findOrFail($roomId);
        
        // Check if user is admin
        $adminParticipant = $room->participants()
            ->where('user_id', $userId)
            ->where('role', 'admin')
            ->first();

        if (!$adminParticipant) {
            throw new \Exception('Only admins can restore chat rooms');
        }

        $room->update(['archived_at' => null]);

        return true;
    }

    /**
     * Get archived chat rooms
     */
    public function getArchivedChatRooms($userId)
    {
        return ChatRoom::whereHas('participants', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->whereNotNull('archived_at')
        ->with(['participants.user:id,name,avatar'])
        ->orderBy('archived_at', 'desc')
        ->get();
    }

    /**
     * Clean old chat messages
     */
    public function cleanOldMessages($days = 365)
    {
        $deletedCount = ChatMessage::where('created_at', '<', now()->subDays($days))
            ->delete();

        Log::info("Cleaned {$deletedCount} old chat messages");

        return $deletedCount;
    }
} 