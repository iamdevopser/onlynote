<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'added_at'
    ];

    protected $casts = [
        'added_at' => 'datetime',
    ];

    /**
     * Get the user that owns the wishlist item.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the course that is in the wishlist.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Scope to get wishlist items for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get wishlist items for a specific course.
     */
    public function scopeForCourse($query, $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    /**
     * Check if a course is in user's wishlist.
     */
    public static function isInWishlist($userId, $courseId)
    {
        return static::where('user_id', $userId)
                    ->where('course_id', $courseId)
                    ->exists();
    }

    /**
     * Add course to wishlist.
     */
    public static function addToWishlist($userId, $courseId)
    {
        return static::firstOrCreate([
            'user_id' => $userId,
            'course_id' => $courseId
        ], [
            'added_at' => now()
        ]);
    }

    /**
     * Remove course from wishlist.
     */
    public static function removeFromWishlist($userId, $courseId)
    {
        return static::where('user_id', $userId)
                    ->where('course_id', $courseId)
                    ->delete();
    }

    /**
     * Get user's wishlist count.
     */
    public static function getUserWishlistCount($userId)
    {
        return static::where('user_id', $userId)->count();
    }
}
