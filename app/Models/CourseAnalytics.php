<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseAnalytics extends Model
{
    protected $fillable = [
        'course_id',
        'instructor_id',
        'date',
        'views',
        'unique_visitors',
        'clicks',
        'avg_watch_time'
    ];

    protected $casts = [
        'date' => 'date',
        'views' => 'integer',
        'unique_visitors' => 'integer',
        'clicks' => 'integer',
        'avg_watch_time' => 'integer'
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
} 