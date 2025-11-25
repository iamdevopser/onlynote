<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardWidget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'widget_type',
        'widget_title',
        'widget_config',
        'position_x',
        'position_y',
        'width',
        'height',
        'is_visible',
        'is_collapsed'
    ];

    protected $casts = [
        'widget_config' => 'array',
        'is_visible' => 'boolean',
        'is_collapsed' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getAvailableWidgets()
    {
        return [
            'earnings_overview' => [
                'title' => 'Earnings Overview',
                'description' => 'Display earnings summary and trends',
                'icon' => 'bx bxs-wallet',
                'default_width' => 6,
                'default_height' => 4
            ],
            'visits_overview' => [
                'title' => 'Visits Overview',
                'description' => 'Show course visits and unique visitors',
                'icon' => 'bx bxs-show',
                'default_width' => 6,
                'default_height' => 4
            ],
            'engagement_overview' => [
                'title' => 'Engagement Overview',
                'description' => 'Display student engagement metrics',
                'icon' => 'bx bxs-heart',
                'default_width' => 6,
                'default_height' => 4
            ],
            'recent_orders' => [
                'title' => 'Recent Orders',
                'description' => 'Show latest course orders',
                'icon' => 'bx bxs-cart',
                'default_width' => 12,
                'default_height' => 3
            ],
            'top_courses' => [
                'title' => 'Top Performing Courses',
                'description' => 'Display best performing courses',
                'icon' => 'bx bxs-star',
                'default_width' => 6,
                'default_height' => 4
            ],
            'student_progress' => [
                'title' => 'Student Progress',
                'description' => 'Show student completion rates',
                'icon' => 'bx bxs-graduation',
                'default_width' => 6,
                'default_height' => 4
            ],
            'quick_stats' => [
                'title' => 'Quick Stats',
                'description' => 'Display key performance indicators',
                'icon' => 'bx bxs-bar-chart-alt-2',
                'default_width' => 12,
                'default_height' => 2
            ]
        ];
    }
} 