<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'rating',
        'title',
        'comment',
        'is_verified',
        'is_approved',
        'helpful_votes',
        'total_votes'
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified' => 'boolean',
        'is_approved' => 'boolean',
        'helpful_votes' => 'integer',
        'total_votes' => 'integer'
    ];

    /**
     * Get the user that wrote the review
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the course being reviewed
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the instructor of the course
     */
    public function instructor()
    {
        return $this->hasOneThrough(User::class, Course::class, 'id', 'id', 'course_id', 'instructor_id');
    }

    /**
     * Get helpful votes for this review
     */
    public function helpfulVotes()
    {
        return $this->hasMany(ReviewVote::class)->where('is_helpful', true);
    }

    /**
     * Get unhelpful votes for this review
     */
    public function unhelpfulVotes()
    {
        return $this->hasMany(ReviewVote::class)->where('is_helpful', false);
    }

    /**
     * Check if review is verified (user purchased the course)
     */
    public function isVerified()
    {
        return $this->is_verified;
    }

    /**
     * Check if review is approved by admin
     */
    public function isApproved()
    {
        return $this->is_approved;
    }

    /**
     * Check if review is published (verified and approved)
     */
    public function isPublished()
    {
        return $this->is_verified && $this->is_approved;
    }

    /**
     * Get helpful percentage
     */
    public function getHelpfulPercentageAttribute()
    {
        if ($this->total_votes === 0) {
            return 0;
        }
        
        return round(($this->helpful_votes / $this->total_votes) * 100);
    }

    /**
     * Get rating stars HTML
     */
    public function getRatingStarsAttribute()
    {
        $stars = '';
        $fullStars = $this->rating;
        $emptyStars = 5 - $this->rating;
        
        for ($i = 0; $i < $fullStars; $i++) {
            $stars .= '<i class="bx bxs-star text-warning"></i>';
        }
        
        for ($i = 0; $i < $emptyStars; $i++) {
            $stars .= '<i class="bx bx-star text-muted"></i>';
        }
        
        return $stars;
    }

    /**
     * Scope for published reviews
     */
    public function scopePublished($query)
    {
        return $query->where('is_verified', true)->where('is_approved', true);
    }

    /**
     * Scope for verified reviews
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope for approved reviews
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope for reviews by rating
     */
    public function scopeByRating($query, $rating)
    {
        return $query->where('rating', $rating);
    }

    /**
     * Scope for recent reviews
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
} 