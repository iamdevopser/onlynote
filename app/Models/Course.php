<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;
    
    protected $guarded = [];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'subcategory_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'instructor_id', 'id');
    }

    public function course_goal()
    {
        return $this->hasMany(CourseGoal::class, 'course_id', 'id');
    }

    /**
     * Get the wishlist items for this course.
     */
    public function wishlistItems()
    {
        return $this->hasMany(Wishlist::class);
    }

    /**
     * Get the users who have this course in their wishlist.
     */
    public function wishlistedBy()
    {
        return $this->belongsToMany(User::class, 'wishlists', 'course_id', 'user_id')
                    ->withTimestamps();
    }

    /**
     * Get the wishlist count for this course.
     */
    public function getWishlistCountAttribute()
    {
        return $this->wishlistItems()->count();
    }

    /**
     * Get the quizzes for this course.
     */
    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    /**
     * Get the active quizzes for this course.
     */
    public function activeQuizzes()
    {
        return $this->hasMany(Quiz::class)->where('is_active', true);
    }

    /**
     * Get the quiz count for this course.
     */
    public function getQuizCountAttribute()
    {
        return $this->quizzes()->count();
    }

    /**
     * Get the active quiz count for this course.
     */
    public function getActiveQuizCountAttribute()
    {
        return $this->activeQuizzes()->count();
    }

}
