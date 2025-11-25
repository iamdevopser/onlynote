<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Widget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class WidgetController extends Controller
{
    /**
     * Display user's dashboard widgets
     */
    public function index()
    {
        $user = Auth::user();
        $widgets = $user->widgets()
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        return view('dashboard.widgets.index', compact('widgets'));
    }

    /**
     * Show widget management page
     */
    public function manage()
    {
        $user = Auth::user();
        $widgets = $user->widgets()->orderBy('position')->get();
        $availableWidgets = $this->getAvailableWidgets();

        return view('dashboard.widgets.manage', compact('widgets', 'availableWidgets'));
    }

    /**
     * Add new widget to dashboard
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:' . implode(',', array_keys($this->getAvailableWidgets())),
            'title' => 'required|string|max:255',
            'position' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        
        // Check if user already has this widget type
        if ($user->widgets()->where('type', $request->type)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Bu widget türü zaten mevcut.'
            ], 422);
        }

        try {
            $widget = Widget::create([
                'user_id' => $user->id,
                'type' => $request->type,
                'title' => $request->title,
                'position' => $request->position,
                'settings' => $this->getDefaultSettings($request->type),
                'is_active' => true,
                'is_collapsible' => true,
                'is_collapsed' => false
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Widget başarıyla eklendi.',
                'widget' => $widget
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Widget eklenirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update widget
     */
    public function update(Request $request, Widget $widget)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'settings' => 'array',
            'is_active' => 'boolean',
            'is_collapsible' => 'boolean',
            'is_collapsed' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user owns this widget
        if ($widget->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            $widget->update($request->only([
                'title', 'settings', 'is_active', 'is_collapsible', 'is_collapsed'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Widget başarıyla güncellendi.',
                'widget' => $widget
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Widget güncellenirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update widget positions (drag & drop)
     */
    public function updatePositions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'positions' => 'required|array',
            'positions.*.id' => 'required|exists:widgets,id',
            'positions.*.position' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        
        try {
            foreach ($request->positions as $item) {
                $widget = $user->widgets()->find($item['id']);
                if ($widget) {
                    $widget->updatePosition($item['position']);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Widget pozisyonları güncellendi.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pozisyonlar güncellenirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle widget collapsed state
     */
    public function toggleCollapsed(Widget $widget)
    {
        // Check if user owns this widget
        if ($widget->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            $widget->toggleCollapsed();

            return response()->json([
                'success' => true,
                'message' => 'Widget durumu güncellendi.',
                'is_collapsed' => $widget->is_collapsed
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Widget durumu güncellenirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete widget
     */
    public function destroy(Widget $widget)
    {
        // Check if user owns this widget
        if ($widget->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            $widget->delete();

            return response()->json([
                'success' => true,
                'message' => 'Widget başarıyla silindi.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Widget silinirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get widget data via AJAX
     */
    public function getData(Widget $widget)
    {
        // Check if user owns this widget
        if ($widget->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            $data = $widget->getData();

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Widget verisi alınırken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available widget types
     */
    private function getAvailableWidgets()
    {
        return [
            'stats' => [
                'name' => 'İstatistikler',
                'description' => 'Önemli sayıları gösterir',
                'icon' => 'chart-pie',
                'color' => 'primary'
            ],
            'chart' => [
                'name' => 'Grafik',
                'description' => 'Veri grafiklerini gösterir',
                'icon' => 'chart-line',
                'color' => 'success'
            ],
            'recent_activities' => [
                'name' => 'Son Aktiviteler',
                'description' => 'Son aktiviteleri listeler',
                'icon' => 'clock',
                'color' => 'info'
            ],
            'quick_actions' => [
                'name' => 'Hızlı İşlemler',
                'description' => 'Sık kullanılan işlemler',
                'icon' => 'bolt',
                'color' => 'warning'
            ],
            'notifications' => [
                'name' => 'Bildirimler',
                'description' => 'Son bildirimleri gösterir',
                'icon' => 'bell',
                'color' => 'danger'
            ],
            'calendar' => [
                'name' => 'Takvim',
                'description' => 'Olayları takvim formatında gösterir',
                'icon' => 'calendar',
                'color' => 'secondary'
            ],
            'weather' => [
                'name' => 'Hava Durumu',
                'description' => 'Güncel hava durumu bilgisi',
                'icon' => 'cloud-sun',
                'color' => 'info'
            ]
        ];
    }

    /**
     * Get default settings for widget type
     */
    private function getDefaultSettings($type)
    {
        $defaults = [
            'stats' => [
                'show_icons' => true,
                'show_percentages' => true
            ],
            'chart' => [
                'chart_type' => 'line',
                'show_legend' => true,
                'responsive' => true
            ],
            'recent_activities' => [
                'limit' => 5,
                'show_avatars' => true
            ],
            'quick_actions' => [
                'show_icons' => true,
                'show_descriptions' => false
            ],
            'notifications' => [
                'limit' => 5,
                'show_unread_only' => false
            ],
            'calendar' => [
                'show_weekends' => true,
                'default_view' => 'month'
            ],
            'weather' => [
                'location' => 'auto',
                'units' => 'celsius'
            ]
        ];

        return $defaults[$type] ?? [];
    }
} 