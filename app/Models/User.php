<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Helper methods for role checking

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isInstructor()
    {
        return $this->role === 'instructor';
    }

    public function isUser()
    {
        return $this->role === 'user';
    }

    // Subscription relationships and methods
    public function userSubscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function activeSubscription()
    {
        return $this->userSubscriptions()
            ->where('status', 'active')
            ->where('end_date', '>=', now())
            ->with('plan')
            ->first();
    }

    public function hasActiveSubscription()
    {
        return $this->activeSubscription() !== null;
    }

    public function canCreateCourse()
    {
        $subscription = $this->activeSubscription();
        if (!$subscription) return false;
        
        $plan = $subscription->plan;
        if ($plan->max_courses == -1) return true; // Unlimited
        
        $courseCount = $this->courses()->count();
        return $courseCount < $plan->max_courses;
    }

    public function subscriptions()
    {
        return $this->hasMany(\App\Models\UserSubscription::class, 'user_id');
    }

    /**
     * Get the user's wishlist items.
     */
    public function wishlist()
    {
        return $this->hasMany(Wishlist::class);
    }

    /**
     * Get the user's wishlist courses.
     */
    public function wishlistCourses()
    {
        return $this->belongsToMany(Course::class, 'wishlists', 'user_id', 'course_id')
                    ->withTimestamps();
    }

    /**
     * Check if a course is in user's wishlist.
     */
    public function hasInWishlist($courseId)
    {
        return $this->wishlist()->where('course_id', $courseId)->exists();
    }

    /**
     * Get the quiz attempts for this user.
     */
    public function quizAttempts()
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Get the completed quiz attempts for this user.
     */
    public function completedQuizAttempts()
    {
        return $this->hasMany(QuizAttempt::class)->where('status', 'completed');
    }

    /**
     * Get the passed quiz attempts for this user.
     */
    public function passedQuizAttempts()
    {
        return $this->hasMany(QuizAttempt::class)->where('passed', true);
    }

    /**
     * Get the quiz attempt count for this user.
     */
    public function getQuizAttemptCountAttribute()
    {
        return $this->quizAttempts()->count();
    }

    /**
     * Get the passed quiz count for this user.
     */
    public function getPassedQuizCountAttribute()
    {
        return $this->passedQuizAttempts()->count();
    }

    /**
     * Get the average quiz score for this user.
     */
    public function getAverageQuizScoreAttribute()
    {
        $completedAttempts = $this->completedQuizAttempts();
        if ($completedAttempts->count() === 0) {
            return 0;
        }

        return $completedAttempts->avg('percentage');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
