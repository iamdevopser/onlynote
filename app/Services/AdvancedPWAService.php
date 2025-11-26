<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdvancedPWAService
{
    protected $cacheName = 'lms-platform-v2';
    protected $offlineData = [];
    
    /**
     * Initialize PWA features
     */
    public function initialize()
    {
        $this->setupOfflineData();
        $this->setupBackgroundSync();
        $this->setupPushNotifications();
        
        return [
            'status' => 'initialized',
            'features' => [
                'offline_sync' => true,
                'background_sync' => true,
                'push_notifications' => true,
                'cache_strategy' => 'network_first'
            ]
        ];
    }

    /**
     * Setup offline data
     */
    private function setupOfflineData()
    {
        $this->offlineData = [
            'courses' => $this->getOfflineCourses(),
            'user_data' => $this->getOfflineUserData(),
            'settings' => $this->getOfflineSettings()
        ];
    }

    /**
     * Get offline courses
     */
    private function getOfflineCourses()
    {
        return Cache::remember('offline_courses', 3600, function () {
            // Get popular courses for offline access
            return \App\Models\Course::with(['category', 'instructor'])
                ->where('status', 'published')
                ->orderBy('enrollment_count', 'desc')
                ->limit(20)
                ->get()
                ->map(function ($course) {
                    return [
                        'id' => $course->id,
                        'title' => $course->title,
                        'description' => $course->description,
                        'thumbnail' => $course->thumbnail,
                        'category' => $course->category->name,
                        'instructor' => $course->instructor->name,
                        'rating' => $course->rating,
                        'enrollment_count' => $course->enrollment_count,
                        'difficulty_level' => $course->difficulty_level,
                        'estimated_duration' => $course->estimated_duration
                    ];
                });
        });
    }

    /**
     * Get offline user data
     */
    private function getOfflineUserData()
    {
        if (!auth()->check()) {
            return [];
        }
        
        $user = auth()->user();
        
        return Cache::remember("offline_user_{$user->id}", 1800, function () use ($user) {
            return [
                'profile' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'role' => $user->role
                ],
                'enrollments' => $user->enrollments()
                    ->with(['course:id,title,thumbnail'])
                    ->get()
                    ->map(function ($enrollment) {
                        return [
                            'course_id' => $enrollment->course_id,
                            'course_title' => $enrollment->course->title,
                            'course_thumbnail' => $enrollment->course->thumbnail,
                            'status' => $enrollment->status,
                            'progress' => $enrollment->progress,
                            'enrolled_at' => $enrollment->created_at
                        ];
                    }),
                'progress' => $user->enrollments()
                    ->where('status', 'in_progress')
                    ->with(['course:id,title,sections'])
                    ->get()
                    ->map(function ($enrollment) {
                        return [
                            'course_id' => $enrollment->course_id,
                            'course_title' => $enrollment->course->title,
                            'sections' => $enrollment->course->sections
                                ->map(function ($section) {
                                    return [
                                        'id' => $section->id,
                                        'title' => $section->title,
                                        'lessons' => $section->lessons
                                            ->map(function ($lesson) {
                                                return [
                                                    'id' => $lesson->id,
                                                    'title' => $lesson->title,
                                                    'type' => $lesson->type,
                                                    'duration' => $lesson->duration
                                                ];
                                            })
                                    ];
                                })
                        ];
                    })
            ];
        });
    }

    /**
     * Get offline settings
     */
    private function getOfflineSettings()
    {
        return Cache::remember('offline_settings', 7200, function () {
            return [
                'app' => [
                    'name' => config('app.name'),
                    'version' => config('app.version', '1.0.0'),
                    'description' => 'Learning Management System'
                ],
                'features' => [
                    'offline_mode' => true,
                    'background_sync' => true,
                    'push_notifications' => true
                ],
                'cache' => [
                    'strategy' => 'network_first',
                    'max_age' => 3600,
                    'max_entries' => 100
                ]
            ];
        });
    }

    /**
     * Setup background sync
     */
    private function setupBackgroundSync()
    {
        $syncTasks = [
            'sync_progress' => [
                'interval' => 300, // 5 minutes
                'handler' => 'syncUserProgress'
            ],
            'sync_offline_actions' => [
                'interval' => 600, // 10 minutes
                'handler' => 'syncOfflineActions'
            ],
            'update_cache' => [
                'interval' => 3600, // 1 hour
                'handler' => 'updateOfflineCache'
            ]
        ];
        
        foreach ($syncTasks as $task => $config) {
            $this->scheduleBackgroundSync($task, $config);
        }
    }

    /**
     * Schedule background sync task
     */
    private function scheduleBackgroundSync($task, $config)
    {
        $cacheKey = "background_sync_{$task}";
        
        if (!Cache::has($cacheKey)) {
            Cache::put($cacheKey, [
                'last_run' => null,
                'next_run' => now()->addSeconds($config['interval']),
                'status' => 'pending'
            ], $config['interval'] * 2);
        }
    }

    /**
     * Setup push notifications
     */
    private function setupPushNotifications()
    {
        $topics = [
            'course_updates',
            'new_lessons',
            'quiz_reminders',
            'achievements',
            'system_notifications'
        ];
        
        foreach ($topics as $topic) {
            $this->registerPushTopic($topic);
        }
    }

    /**
     * Register push notification topic
     */
    private function registerPushTopic($topic)
    {
        $cacheKey = "push_topic_{$topic}";
        
        if (!Cache::has($cacheKey)) {
            Cache::put($cacheKey, [
                'topic' => $topic,
                'subscribers' => 0,
                'last_notification' => null,
                'is_active' => true
            ], 86400); // 24 hours
        }
    }

    /**
     * Sync user progress in background
     */
    public function syncUserProgress()
    {
        if (!auth()->check()) {
            return false;
        }
        
        $user = auth()->user();
        $offlineProgress = Cache::get("offline_progress_{$user->id}", []);
        
        if (empty($offlineProgress)) {
            return true;
        }
        
        $syncedCount = 0;
        
        foreach ($offlineProgress as $progress) {
            try {
                $enrollment = $user->enrollments()
                    ->where('course_id', $progress['course_id'])
                    ->first();
                
                if ($enrollment) {
                    $enrollment->update([
                        'progress' => $progress['progress'],
                        'last_accessed_at' => now()
                    ]);
                    
                    $syncedCount++;
                }
            } catch (\Exception $e) {
                Log::error("Failed to sync progress for course {$progress['course_id']}: " . $e->getMessage());
            }
        }
        
        // Clear synced progress
        Cache::forget("offline_progress_{$user->id}");
        
        Log::info("Synced {$syncedCount} progress updates for user {$user->id}");
        
        return $syncedCount;
    }

    /**
     * Sync offline actions
     */
    public function syncOfflineActions()
    {
        if (!auth()->check()) {
            return false;
        }
        
        $user = auth()->user();
        $offlineActions = Cache::get("offline_actions_{$user->id}", []);
        
        if (empty($offlineActions)) {
            return true;
        }
        
        $syncedCount = 0;
        
        foreach ($offlineActions as $action) {
            try {
                switch ($action['type']) {
                    case 'quiz_attempt':
                        $this->syncQuizAttempt($action['data']);
                        break;
                    case 'course_note':
                        $this->syncCourseNote($action['data']);
                        break;
                    case 'lesson_completion':
                        $this->syncLessonCompletion($action['data']);
                        break;
                }
                
                $syncedCount++;
            } catch (\Exception $e) {
                Log::error("Failed to sync action {$action['type']}: " . $e->getMessage());
            }
        }
        
        // Clear synced actions
        Cache::forget("offline_actions_{$user->id}");
        
        Log::info("Synced {$syncedCount} offline actions for user {$user->id}");
        
        return $syncedCount;
    }

    /**
     * Sync quiz attempt
     */
    private function syncQuizAttempt($data)
    {
        $quizAttempt = \App\Models\QuizAttempt::create([
            'user_id' => $data['user_id'],
            'quiz_id' => $data['quiz_id'],
            'answers' => $data['answers'],
            'score' => $data['score'],
            'started_at' => $data['started_at'],
            'completed_at' => $data['completed_at'],
            'is_offline' => false
        ]);
        
        return $quizAttempt;
    }

    /**
     * Sync course note
     */
    private function syncCourseNote($data)
    {
        $note = \App\Models\CourseNote::create([
            'user_id' => $data['user_id'],
            'course_id' => $data['course_id'],
            'lesson_id' => $data['lesson_id'],
            'content' => $data['content'],
            'timestamp' => $data['timestamp'],
            'is_offline' => false
        ]);
        
        return $note;
    }

    /**
     * Sync lesson completion
     */
    private function syncLessonCompletion($data)
    {
        $completion = \App\Models\LessonCompletion::create([
            'user_id' => $data['user_id'],
            'lesson_id' => $data['lesson_id'],
            'completed_at' => $data['completed_at'],
            'is_offline' => false
        ]);
        
        return $completion;
    }

    /**
     * Update offline cache
     */
    public function updateOfflineCache()
    {
        // Update offline courses
        Cache::forget('offline_courses');
        $this->getOfflineCourses();
        
        // Update offline user data for all users
        $users = \App\Models\User::where('last_login_at', '>=', now()->subDays(7))->get();
        
        foreach ($users as $user) {
            Cache::forget("offline_user_{$user->id}");
            $this->getOfflineUserData();
        }
        
        // Update offline settings
        Cache::forget('offline_settings');
        $this->getOfflineSettings();
        
        Log::info('Offline cache updated successfully');
        
        return true;
    }

    /**
     * Store offline action
     */
    public function storeOfflineAction($type, $data)
    {
        if (!auth()->check()) {
            return false;
        }
        
        $user = auth()->user();
        $cacheKey = "offline_actions_{$user->id}";
        
        $actions = Cache::get($cacheKey, []);
        $actions[] = [
            'type' => $type,
            'data' => $data,
            'created_at' => now()->toISOString()
        ];
        
        Cache::put($cacheKey, $actions, 86400); // 24 hours
        
        return true;
    }

    /**
     * Store offline progress
     */
    public function storeOfflineProgress($courseId, $progress)
    {
        if (!auth()->check()) {
            return false;
        }
        
        $user = auth()->user();
        $cacheKey = "offline_progress_{$user->id}";
        
        $progressData = Cache::get($cacheKey, []);
        
        // Update existing progress or add new
        $existingIndex = collect($progressData)->search(function ($item) use ($courseId) {
            return $item['course_id'] === $courseId;
        });
        
        if ($existingIndex !== false) {
            $progressData[$existingIndex]['progress'] = $progress;
            $progressData[$existingIndex]['updated_at'] = now()->toISOString();
        } else {
            $progressData[] = [
                'course_id' => $courseId,
                'progress' => $progress,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString()
            ];
        }
        
        Cache::put($cacheKey, $progressData, 86400); // 24 hours
        
        return true;
    }

    /**
     * Get offline status
     */
    public function getOfflineStatus()
    {
        return [
            'is_online' => $this->checkOnlineStatus(),
            'offline_data' => [
                'courses_count' => count($this->offlineData['courses']),
                'user_data_size' => strlen(json_encode($this->offlineData['user_data'])),
                'last_sync' => Cache::get('last_background_sync', 'Never')
            ],
            'background_sync' => [
                'status' => 'active',
                'next_sync' => Cache::get('next_background_sync', 'Unknown')
            ]
        ];
    }

    /**
     * Check online status
     */
    private function checkOnlineStatus()
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)->get(config('app.url'));
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get PWA manifest
     */
    public function getPWAManifest()
    {
        return [
            'name' => config('app.name'),
            'short_name' => 'LMS',
            'description' => 'Learning Management System',
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#667eea',
            'orientation' => 'portrait-primary',
            'scope' => '/',
            'lang' => app()->getLocale(),
            'categories' => ['education', 'productivity'],
            'icons' => $this->getPWAIcons(),
            'shortcuts' => $this->getPWAShortcuts(),
            'screenshots' => $this->getPWAScreenshots()
        ];
    }

    /**
     * Get PWA icons
     */
    private function getPWAIcons()
    {
        return [
            [
                'src' => '/images/icon-72x72.png',
                'sizes' => '72x72',
                'type' => 'image/png',
                'purpose' => 'maskable any'
            ],
            [
                'src' => '/images/icon-96x96.png',
                'sizes' => '96x96',
                'type' => 'image/png',
                'purpose' => 'maskable any'
            ],
            [
                'src' => '/images/icon-128x128.png',
                'sizes' => '128x128',
                'type' => 'image/png',
                'purpose' => 'maskable any'
            ],
            [
                'src' => '/images/icon-144x144.png',
                'sizes' => '144x144',
                'type' => 'image/png',
                'purpose' => 'maskable any'
            ],
            [
                'src' => '/images/icon-152x152.png',
                'sizes' => '152x152',
                'type' => 'image/png',
                'purpose' => 'maskable any'
            ],
            [
                'src' => '/images/icon-192x192.png',
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'maskable any'
            ],
            [
                'src' => '/images/icon-384x384.png',
                'sizes' => '384x384',
                'type' => 'image/png',
                'purpose' => 'maskable any'
            ],
            [
                'src' => '/images/icon-512x512.png',
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'maskable any'
            ]
        ];
    }

    /**
     * Get PWA shortcuts
     */
    private function getPWAShortcuts()
    {
        return [
            [
                'name' => 'Dashboard',
                'short_name' => 'Dashboard',
                'description' => 'Ana dashboard sayfası',
                'url' => '/dashboard',
                'icons' => [
                    [
                        'src' => '/images/dashboard-icon.png',
                        'sizes' => '96x96'
                    ]
                ]
            ],
            [
                'name' => 'Kurslar',
                'short_name' => 'Kurslar',
                'description' => 'Kurs listesi',
                'url' => '/courses',
                'icons' => [
                    [
                        'src' => '/images/courses-icon.png',
                        'sizes' => '96x96'
                    ]
                ]
            ],
            [
                'name' => 'Profil',
                'short_name' => 'Profil',
                'description' => 'Kullanıcı profili',
                'url' => '/profile',
                'icons' => [
                    [
                        'src' => '/images/profile-icon.png',
                        'sizes' => '96x96'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get PWA screenshots
     */
    private function getPWAScreenshots()
    {
        return [
            [
                'src' => '/images/screenshot-wide.png',
                'sizes' => '1280x720',
                'type' => 'image/png',
                'form_factor' => 'wide'
            ],
            [
                'src' => '/images/screenshot-narrow.png',
                'sizes' => '750x1334',
                'type' => 'image/png',
                'form_factor' => 'narrow'
            ]
        ];
    }
} 