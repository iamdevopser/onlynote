<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EarningsAnalytics extends Model
{
    protected $fillable = [
        'instructor_id',
        'course_id',
        'date',
        'total_earnings',
        'currency',
        'payment_type',
        'order_count'
    ];

    protected $casts = [
        'date' => 'date',
        'total_earnings' => 'decimal:2',
        'order_count' => 'integer'
    ];

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
} 