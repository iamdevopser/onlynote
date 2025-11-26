<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Badge;
use App\Models\UserBadge;
use App\Models\Leaderboard;
use App\Models\LeaderboardEntry;
use App\Models\User;
use App\Models\Course;

class GamificationService
{
    /**
     * Create a new badge
     */
    public function createBadge(array $data): Badge
    {
        try {
            $badge = Badge::create([
                'name' => $data['name'],
                'description' => $data['description'],
                'icon' => $data['icon'],
                'type' => $data['type'] ?? 'achievement',
                'rarity' => $data['rarity'] ?? 'common',
                'points' => $data['points'] ?? 0,
                'criteria_type' => $data['criteria_type'],
                'criteria_value' => $data['criteria_value'],
                'criteria_operator' => $data['criteria_operator'] ?? '>=',
                'is_active' => $data['is_active'] ?? true,
                'is_hidden' => $data['is_hidden'] ?? false,
                'unlock_message' => $data['unlock_message'] ?? null,
                'metadata' => $data['metadata'] ?? []
            ]);

            Log::info("Badge created successfully", [
                'badge_id' => $badge->id,
                'name' => $badge->name
            ]);

            return $badge;

        } catch (\Exception $e) {
            Log::error("Failed to create badge: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update a badge
     */
    public function updateBadge(Badge $badge, array $data): Badge
    {
        try {
            $badge->update($data);
            
            Log::info("Badge updated successfully", [
                'badge_id' => $badge->id
            ]);

            return $badge;

        } catch (\Exception $e) {
            Log::error("Failed to update badge: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete a badge
     */
    public function deleteBadge(Badge $badge): bool
    {
        try {
            // Check if badge has been awarded to users
            if ($badge->users()->count() > 0) {
                throw new \Exception('Cannot delete badge that has been awarded to users.');
            }

            $badge->delete();
            
            Log::info("Badge deleted successfully", [
                'badge_id' => $badge->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to delete badge: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check and award badges to user
     */
    public function checkAndAwardBadges(User $user): array
    {
        $awardedBadges = [];
        
        try {
            $activeBadges = Badge::active()->visible()->get();
            
            foreach ($activeBadges as $badge) {
                // Skip if user already has this badge
                if ($user->badges()->where('badge_id', $badge->id)->exists()) {
                    continue;
                }

                // Check if user meets criteria
                if ($badge->checkCriteria($user)) {
                    $userBadge = $this->awardBadge($user, $badge);
                    if ($userBadge) {
                        $awardedBadges[] = $userBadge;
                    }
                }
            }

            Log::info("Badge check completed for user", [
                'user_id' => $user->id,
                'awarded_count' => count($awardedBadges)
            ]);

            return $awardedBadges;

        } catch (\Exception $e) {
            Log::error("Failed to check badges for user: " . $e->getMessage());
            return $awardedBadges;
        }
    }

    /**
     * Award a specific badge to user
     */
    public function awardBadge(User $user, Badge $badge): ?UserBadge
    {
        try {
            // Check if user already has this badge
            if ($user->badges()->where('badge_id', $badge->id)->exists()) {
                return null;
            }

            // Calculate progress
            $progress = $this->calculateUserProgress($user, $badge);

            // Create user badge record
            $userBadge = UserBadge::create([
                'user_id' => $user->id,
                'badge_id' => $badge->id,
                'earned_at' => now(),
                'progress' => $progress,
                'metadata' => []
            ]);

            // Add points to user
            if ($badge->points > 0) {
                $user->increment('points', $badge->points);
            }

            Log::info("Badge awarded to user", [
                'user_id' => $user->id,
                'badge_id' => $badge->id,
                'badge_name' => $badge->name,
                'points_awarded' => $badge->points
            ]);

            return $userBadge;

        } catch (\Exception $e) {
            Log::error("Failed to award badge: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate user's progress towards a badge
     */
    private function calculateUserProgress(User $user, Badge $badge): int
    {
        switch ($badge->criteria_type) {
            case 'course_completion':
                return $user->enrollments()->where('status', 'completed')->count();
            case 'quiz_score':
                return round($user->quizAttempts()->avg('percentage') ?? 0);
            case 'assignment_count':
                return $user->assignmentSubmissions()->count();
            case 'discussion_posts':
                return $user->discussionReplies()->count();
            case 'live_class_attendance':
                return $user->liveClassParticipants()->count();
            case 'streak_days':
                return $this->calculateUserStreak($user);
            case 'total_points':
                return $user->points ?? 0;
            default:
                return 0;
        }
    }

    /**
     * Calculate user's current learning streak
     */
    private function calculateUserStreak(User $user): int
    {
        // This is a simplified version - you can implement more sophisticated streak logic
        $enrollments = $user->enrollments()
            ->whereNotNull('last_accessed_at')
            ->orderBy('last_accessed_at', 'desc')
            ->get();

        if ($enrollments->isEmpty()) {
            return 0;
        }

        $streak = 0;
        $currentDate = now()->startOfDay();

        foreach ($enrollments as $enrollment) {
            $lastAccess = \Carbon\Carbon::parse($enrollment->last_accessed_at)->startOfDay();
            $diff = $currentDate->diffInDays($lastAccess);

            if ($diff <= 1) {
                $streak++;
                $currentDate = $lastAccess;
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Create a new leaderboard
     */
    public function createLeaderboard(array $data): Leaderboard
    {
        try {
            $leaderboard = Leaderboard::create([
                'name' => $data['name'],
                'description' => $data['description'],
                'type' => $data['type'],
                'course_id' => $data['course_id'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'sort_by' => $data['sort_by'] ?? 'points',
                'sort_order' => $data['sort_order'] ?? 'desc',
                'max_entries' => $data['max_entries'] ?? 100,
                'metadata' => $data['metadata'] ?? []
            ]);

            Log::info("Leaderboard created successfully", [
                'leaderboard_id' => $leaderboard->id,
                'name' => $leaderboard->name
            ]);

            return $leaderboard;

        } catch (\Exception $e) {
            Log::error("Failed to create leaderboard: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update leaderboard entries
     */
    public function updateLeaderboardEntries(Leaderboard $leaderboard): bool
    {
        try {
            // Clear existing entries
            $leaderboard->entries()->delete();

            // Get users based on leaderboard type
            $users = $this->getLeaderboardUsers($leaderboard);

            foreach ($users as $user) {
                $score = $this->calculateLeaderboardScore($user, $leaderboard);
                
                LeaderboardEntry::create([
                    'leaderboard_id' => $leaderboard->id,
                    'user_id' => $user->id,
                    'score' => $score,
                    'rank' => 0, // Will be calculated after all entries are created
                    'metadata' => []
                ]);
            }

            // Calculate ranks
            $this->calculateLeaderboardRanks($leaderboard);

            Log::info("Leaderboard entries updated successfully", [
                'leaderboard_id' => $leaderboard->id,
                'entries_count' => $users->count()
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to update leaderboard entries: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get users for a leaderboard
     */
    private function getLeaderboardUsers(Leaderboard $leaderboard): \Illuminate\Database\Eloquent\Collection
    {
        if ($leaderboard->type === 'course' && $leaderboard->course_id) {
            return User::whereHas('enrollments', function($q) use ($leaderboard) {
                $q->where('course_id', $leaderboard->course_id);
            })->get();
        }

        return User::all();
    }

    /**
     * Calculate user's score for a leaderboard
     */
    private function calculateLeaderboardScore(User $user, Leaderboard $leaderboard): float
    {
        switch ($leaderboard->sort_by) {
            case 'points':
                return $user->points ?? 0;
            case 'badges':
                return $user->badges()->count();
            case 'courses_completed':
                return $user->enrollments()->where('status', 'completed')->count();
            case 'quiz_score':
                return round($user->quizAttempts()->avg('percentage') ?? 0, 2);
            case 'assignment_count':
                return $user->assignmentSubmissions()->count();
            case 'discussion_posts':
                return $user->discussionReplies()->count();
            case 'live_class_attendance':
                return $user->liveClassParticipants()->count();
            case 'streak_days':
                return $this->calculateUserStreak($user);
            default:
                return 0;
        }
    }

    /**
     * Calculate ranks for leaderboard entries
     */
    private function calculateLeaderboardRanks(Leaderboard $leaderboard): void
    {
        $entries = $leaderboard->entries()
            ->orderBy('score', $leaderboard->sort_order)
            ->get();

        $rank = 1;
        $previousScore = null;
        $previousRank = 1;

        foreach ($entries as $entry) {
            // Handle ties
            if ($previousScore !== null && $entry->score != $previousScore) {
                $rank = $previousRank + 1;
            }

            $entry->update(['rank' => $rank]);
            
            $previousScore = $entry->score;
            $previousRank = $rank;
        }
    }

    /**
     * Get leaderboard top performers
     */
    public function getLeaderboardTopPerformers(Leaderboard $leaderboard, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $leaderboard->entries()
            ->with('user')
            ->orderBy('rank', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get user's position in leaderboard
     */
    public function getUserLeaderboardPosition(Leaderboard $leaderboard, User $user): ?int
    {
        $entry = $leaderboard->entries()->where('user_id', $user->id)->first();
        return $entry ? $entry->rank : null;
    }

    /**
     * Get user's gamification statistics
     */
    public function getUserGamificationStats(User $user): array
    {
        $totalBadges = $user->badges()->count();
        $recentBadges = $user->badges()->recentlyEarned()->count();
        $totalPoints = $user->points ?? 0;
        $currentStreak = $this->calculateUserStreak($user);
        $longestStreak = $user->metadata['longest_streak'] ?? 0;
        
        // Calculate level based on points
        $level = $this->calculateUserLevel($totalPoints);
        $pointsToNextLevel = $this->getPointsToNextLevel($totalPoints);

        return [
            'total_badges' => $totalBadges,
            'recent_badges' => $recentBadges,
            'total_points' => $totalPoints,
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'level' => $level,
            'points_to_next_level' => $pointsToNextLevel,
            'level_progress' => $this->getLevelProgress($totalPoints)
        ];
    }

    /**
     * Calculate user level based on points
     */
    private function calculateUserLevel(int $points): int
    {
        // Simple level calculation: every 1000 points = 1 level
        return floor($points / 1000) + 1;
    }

    /**
     * Get points needed for next level
     */
    private function getPointsToNextLevel(int $points): int
    {
        $currentLevel = $this->calculateUserLevel($points);
        $nextLevelPoints = $currentLevel * 1000;
        return max(0, $nextLevelPoints - $points);
    }

    /**
     * Get level progress percentage
     */
    private function getLevelProgress(int $points): int
    {
        $currentLevel = $this->calculateUserLevel($points);
        $levelStartPoints = ($currentLevel - 1) * 1000;
        $levelEndPoints = $currentLevel * 1000;
        $levelPoints = $points - $levelStartPoints;
        $levelTotalPoints = $levelEndPoints - $levelStartPoints;
        
        return $levelTotalPoints > 0 ? round(($levelPoints / $levelTotalPoints) * 100) : 0;
    }
}










