<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEngagement extends Model
{
    protected $fillable = [
        'course_id',
        'user_id',
        'instructor_id',
        'engagement_type',
        'engagement_value',
        'date',
        'meta'
    ];

    protected $casts = [
        'date' => 'date',
        'meta' => 'array'
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
} 