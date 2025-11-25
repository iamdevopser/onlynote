<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'position',
        'settings',
        'is_active',
        'is_collapsible',
        'is_collapsed'
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_collapsible' => 'boolean',
        'is_collapsed' => 'boolean'
    ];

    /**
     * Get the user that owns the widget
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get widget data based on type
     */
    public function getData()
    {
        switch ($this->type) {
            case 'stats':
                return $this->getStatsData();
            case 'chart':
                return $this->getChartData();
            case 'recent_activities':
                return $this->getRecentActivitiesData();
            case 'quick_actions':
                return $this->getQuickActionsData();
            case 'notifications':
                return $this->getNotificationsData();
            case 'calendar':
                return $this->getCalendarData();
            case 'weather':
                return $this->getWeatherData();
            default:
                return [];
        }
    }

    /**
     * Get stats widget data
     */
    private function getStatsData()
    {
        $user = $this->user;
        
        if ($user->isInstructor()) {
            return [
                'total_courses' => $user->courses()->count(),
                'total_students' => $user->courses()->withCount('enrollments')->get()->sum('enrollments_count'),
                'total_earnings' => $user->earnings()->sum('amount'),
                'monthly_earnings' => $user->earnings()->whereMonth('created_at', now()->month)->sum('amount')
            ];
        } else {
            return [
                'enrolled_courses' => $user->enrollments()->count(),
                'completed_courses' => $user->enrollments()->where('status', 'completed')->count(),
                'total_certificates' => $user->certificates()->count(),
                'learning_hours' => $user->enrollments()->sum('learning_hours')
            ];
        }
    }

    /**
     * Get chart widget data
     */
    private function getChartData()
    {
        $user = $this->user;
        $chartType = $this->settings['chart_type'] ?? 'line';
        
        if ($user->isInstructor()) {
            $data = $user->earnings()
                ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
                ->whereBetween('created_at', [now()->subDays(30), now()])
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        } else {
            $data = $user->enrollments()
                ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
                ->whereBetween('created_at', [now()->subDays(30), now()])
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        }

        return [
            'type' => $chartType,
            'labels' => $data->pluck('date')->toArray(),
            'data' => $data->pluck('total')->toArray()
        ];
    }

    /**
     * Get recent activities data
     */
    private function getRecentActivitiesData()
    {
        $user = $this->user;
        $limit = $this->settings['limit'] ?? 5;
        
        if ($user->isInstructor()) {
            return $user->courses()
                ->with(['enrollments' => function($query) {
                    $query->latest()->limit($limit);
                }])
                ->get()
                ->flatMap(function($course) {
                    return $course->enrollments->map(function($enrollment) use ($course) {
                        return [
                            'type' => 'enrollment',
                            'message' => "Yeni öğrenci {$course->title} kursuna kayıt oldu",
                            'time' => $enrollment->created_at->diffForHumans(),
                            'icon' => 'user-plus'
                        ];
                    });
                })
                ->take($limit);
        } else {
            return $user->enrollments()
                ->with('course')
                ->latest()
                ->limit($limit)
                ->get()
                ->map(function($enrollment) {
                    return [
                        'type' => 'course',
                        'message' => "{$enrollment->course->title} kursuna kayıt oldunuz",
                        'time' => $enrollment->created_at->diffForHumans(),
                        'icon' => 'book-open'
                    ];
                });
        }
    }

    /**
     * Get quick actions data
     */
    private function getQuickActionsData()
    {
        $user = $this->user;
        
        if ($user->isInstructor()) {
            return [
                [
                    'title' => 'Yeni Kurs Oluştur',
                    'url' => route('instructor.courses.create'),
                    'icon' => 'plus-circle',
                    'color' => 'primary'
                ],
                [
                    'title' => 'Quiz Oluştur',
                    'url' => route('instructor.quizzes.create'),
                    'icon' => 'question-circle',
                    'color' => 'success'
                ],
                [
                    'title' => 'Analitik Raporu',
                    'url' => route('instructor.analytics.performance'),
                    'icon' => 'chart-bar',
                    'color' => 'info'
                ],
                [
                    'title' => 'Canlı Ders',
                    'url' => route('instructor.live.schedule'),
                    'icon' => 'video',
                    'color' => 'warning'
                ]
            ];
        } else {
            return [
                [
                    'title' => 'Kurs Ara',
                    'url' => route('courses.search'),
                    'icon' => 'search',
                    'color' => 'primary'
                ],
                [
                    'title' => 'Wishlist',
                    'url' => route('user.wishlist.index'),
                    'icon' => 'heart',
                    'color' => 'danger'
                ],
                [
                    'title' => 'Sertifikalarım',
                    'url' => route('user.certificates'),
                    'icon' => 'certificate',
                    'color' => 'success'
                ],
                [
                    'title' => 'Profil',
                    'url' => route('user.profile'),
                    'icon' => 'user',
                    'color' => 'info'
                ]
            ];
        }
    }

    /**
     * Get notifications data
     */
    private function getNotificationsData()
    {
        $user = $this->user;
        $limit = $this->settings['limit'] ?? 5;
        
        return $user->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function($notification) {
                return [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? 'Bildirim',
                    'message' => $notification->data['message'] ?? '',
                    'time' => $notification->created_at->diffForHumans(),
                    'is_read' => $notification->read_at !== null,
                    'type' => $notification->data['type'] ?? 'info'
                ];
            });
    }

    /**
     * Get calendar data
     */
    private function getCalendarData()
    {
        $user = $this->user;
        $month = $this->settings['month'] ?? now()->month;
        $year = $this->settings['year'] ?? now()->year;
        
        if ($user->isInstructor()) {
            $events = $user->courses()
                ->with(['enrollments' => function($query) use ($month, $year) {
                    $query->whereMonth('created_at', $month)
                          ->whereYear('created_at', $year);
                }])
                ->get()
                ->flatMap(function($course) {
                    return $course->enrollments->map(function($enrollment) use ($course) {
                        return [
                            'title' => "Yeni Öğrenci: {$course->title}",
                            'date' => $enrollment->created_at->format('Y-m-d'),
                            'color' => 'success'
                        ];
                    });
                });
        } else {
            $events = $user->enrollments()
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->with('course')
                ->get()
                ->map(function($enrollment) {
                    return [
                        'title' => "Kurs: {$enrollment->course->title}",
                        'date' => $enrollment->created_at->format('Y-m-d'),
                        'color' => 'primary'
                    ];
                });
        }

        return $events->toArray();
    }

    /**
     * Get weather data (placeholder)
     */
    private function getWeatherData()
    {
        // This would integrate with a weather API
        return [
            'temperature' => '22°C',
            'condition' => 'Güneşli',
            'icon' => 'sun',
            'location' => 'İstanbul, TR'
        ];
    }

    /**
     * Update widget position
     */
    public function updatePosition($position)
    {
        $this->update(['position' => $position]);
    }

    /**
     * Toggle widget collapsed state
     */
    public function toggleCollapsed()
    {
        $this->update(['is_collapsed' => !$this->is_collapsed]);
    }

    /**
     * Get widget settings
     */
    public function getSetting($key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Set widget setting
     */
    public function setSetting($key, $value)
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->update(['settings' => $settings]);
    }
} 