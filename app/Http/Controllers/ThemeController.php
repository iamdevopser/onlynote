<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ThemeController extends Controller
{
    protected $themeService;
    
    public function __construct(ThemeService $themeService)
    {
        $this->themeService = $themeService;
    }

    /**
     * Set theme for user
     */
    public function setTheme(Request $request, $theme): JsonResponse
    {
        $request->validate([
            'theme' => 'required|in:light,dark,auto'
        ]);
        
        $userId = auth()->id();
        $selectedTheme = $request->input('theme', $theme);
        
        $this->themeService->setTheme($selectedTheme, $userId);
        
        return response()->json([
            'success' => true,
            'theme' => $selectedTheme,
            'effective_theme' => $this->themeService->getEffectiveTheme($selectedTheme),
            'message' => 'Tema başarıyla değiştirildi.'
        ]);
    }

    /**
     * Get current theme
     */
    public function getCurrentTheme(): JsonResponse
    {
        $userId = auth()->id();
        $currentTheme = $this->themeService->getCurrentTheme($userId);
        $effectiveTheme = $this->themeService->getEffectiveTheme($currentTheme);
        
        return response()->json([
            'current_theme' => $currentTheme,
            'effective_theme' => $effectiveTheme,
            'theme_variables' => $this->themeService->getThemeVariables($effectiveTheme),
            'available_themes' => $this->themeService->getAvailableThemes()
        ]);
    }

    /**
     * Get theme CSS variables
     */
    public function getThemeCSS($theme = null): JsonResponse
    {
        $theme = $theme ?: $this->themeService->getCurrentTheme();
        $effectiveTheme = $this->themeService->getEffectiveTheme($theme);
        
        return response()->json([
            'theme' => $effectiveTheme,
            'css' => $this->themeService->getThemeCSS($effectiveTheme),
            'variables' => $this->themeService->getThemeVariables($effectiveTheme)
        ]);
    }

    /**
     * Reset user theme preferences
     */
    public function resetTheme(): JsonResponse
    {
        $userId = auth()->id();
        $defaultTheme = $this->themeService->resetTheme($userId);
        
        return response()->json([
            'success' => true,
            'theme' => $defaultTheme,
            'message' => 'Tema tercihleri sıfırlandı.'
        ]);
    }

    /**
     * Get theme preview
     */
    public function getThemePreview($theme): JsonResponse
    {
        $effectiveTheme = $this->themeService->getEffectiveTheme($theme);
        
        return response()->json([
            'theme' => $theme,
            'effective_theme' => $effectiveTheme,
            'preview' => [
                'name' => $this->themeService->getAvailableThemes()[$theme]['name'] ?? '',
                'description' => $this->themeService->getAvailableThemes()[$theme]['description'] ?? '',
                'icon' => $this->themeService->getAvailableThemes()[$theme]['icon'] ?? '',
                'variables' => $this->themeService->getThemeVariables($effectiveTheme),
                'sample_colors' => $this->getSampleColors($effectiveTheme)
            ]
        ]);
    }

    /**
     * Get sample colors for theme preview
     */
    private function getSampleColors($theme)
    {
        $variables = $this->themeService->getThemeVariables($theme);
        
        return [
            'primary' => $variables['--primary-color'] ?? '#667eea',
            'secondary' => $variables['--secondary-color'] ?? '#764ba2',
            'background' => $variables['--body-bg'] ?? '#ffffff',
            'text' => $variables['--body-color'] ?? '#212529',
            'card' => $variables['--card-bg'] ?? '#ffffff',
            'border' => $variables['--card-border'] ?? '#dee2e6'
        ];
    }

    /**
     * Get theme statistics
     */
    public function getThemeStats(): JsonResponse
    {
        $stats = [
            'total_users' => \App\Models\User::count(),
            'theme_distribution' => $this->getThemeDistribution(),
            'popular_themes' => $this->getPopularThemes(),
            'recent_changes' => $this->getRecentThemeChanges()
        ];
        
        return response()->json($stats);
    }

    /**
     * Get theme distribution
     */
    private function getThemeDistribution()
    {
        $themes = ['light', 'dark', 'auto'];
        $distribution = [];
        
        foreach ($themes as $theme) {
            $count = \App\Models\User::where('preferred_theme', $theme)->count();
            $distribution[$theme] = $count;
        }
        
        return $distribution;
    }

    /**
     * Get popular themes
     */
    private function getPopularThemes()
    {
        return \App\Models\User::selectRaw('preferred_theme, COUNT(*) as count')
            ->whereNotNull('preferred_theme')
            ->groupBy('preferred_theme')
            ->orderByDesc('count')
            ->limit(3)
            ->get();
    }

    /**
     * Get recent theme changes
     */
    private function getRecentThemeChanges()
    {
        // This would require a theme_change_logs table
        // For now, return empty array
        return [];
    }

    /**
     * Export theme configuration
     */
    public function exportTheme($theme): JsonResponse
    {
        $effectiveTheme = $this->themeService->getEffectiveTheme($theme);
        $variables = $this->themeService->getThemeVariables($effectiveTheme);
        
        $config = [
            'theme' => $theme,
            'effective_theme' => $effectiveTheme,
            'variables' => $variables,
            'css' => $this->themeService->getThemeCSS($effectiveTheme),
            'metadata' => [
                'exported_at' => now()->toISOString(),
                'version' => '1.0.0',
                'platform' => 'LMS Platform'
            ]
        ];
        
        return response()->json($config);
    }

    /**
     * Import theme configuration
     */
    public function importTheme(Request $request): JsonResponse
    {
        $request->validate([
            'theme_config' => 'required|json'
        ]);
        
        try {
            $config = json_decode($request->input('theme_config'), true);
            
            if (!$config || !isset($config['variables'])) {
                throw new \Exception('Geçersiz tema konfigürasyonu');
            }
            
            // Store custom theme configuration
            $this->storeCustomTheme($config);
            
            return response()->json([
                'success' => true,
                'message' => 'Tema konfigürasyonu başarıyla içe aktarıldı.'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tema içe aktarımı başarısız: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Store custom theme configuration
     */
    private function storeCustomTheme($config)
    {
        $themeName = $config['theme'] ?? 'custom_' . time();
        
        // Store in database or cache
        \Illuminate\Support\Facades\Cache::put(
            "custom_theme_{$themeName}",
            $config,
            86400 * 30 // 30 days
        );
    }

    /**
     * Get custom themes
     */
    public function getCustomThemes(): JsonResponse
    {
        $customThemes = [];
        $cacheKeys = \Illuminate\Support\Facades\Cache::get('custom_theme_keys', []);
        
        foreach ($cacheKeys as $key) {
            $theme = \Illuminate\Support\Facades\Cache::get($key);
            if ($theme) {
                $customThemes[] = $theme;
            }
        }
        
        return response()->json([
            'custom_themes' => $customThemes,
            'count' => count($customThemes)
        ]);
    }

    /**
     * Delete custom theme
     */
    public function deleteCustomTheme($themeName): JsonResponse
    {
        $cacheKey = "custom_theme_{$themeName}";
        
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            
            // Remove from keys list
            $keys = \Illuminate\Support\Facades\Cache::get('custom_theme_keys', []);
            $keys = array_diff($keys, [$cacheKey]);
            \Illuminate\Support\Facades\Cache::put('custom_theme_keys', $keys, 86400 * 30);
            
            return response()->json([
                'success' => true,
                'message' => 'Özel tema başarıyla silindi.'
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Tema bulunamadı.'
        ], 404);
    }

    /**
     * Get theme accessibility features
     */
    public function getAccessibilityFeatures(): JsonResponse
    {
        $features = [
            'high_contrast' => [
                'enabled' => true,
                'description' => 'Yüksek kontrast modu',
                'variables' => [
                    '--body-bg' => '#000000',
                    '--body-color' => '#ffffff',
                    '--card-bg' => '#1a1a1a',
                    '--card-border' => '#ffffff'
                ]
            ],
            'large_text' => [
                'enabled' => true,
                'description' => 'Büyük yazı tipi',
                'variables' => [
                    '--font-size-base' => '18px',
                    '--font-size-lg' => '20px',
                    '--font-size-xl' => '24px'
                ]
            ],
            'reduced_motion' => [
                'enabled' => true,
                'description' => 'Azaltılmış hareket',
                'variables' => [
                    '--transition' => 'none',
                    '--animation-duration' => '0s'
                ]
            ]
        ];
        
        return response()->json([
            'accessibility_features' => $features,
            'user_preferences' => $this->getUserAccessibilityPreferences()
        ]);
    }

    /**
     * Get user accessibility preferences
     */
    private function getUserAccessibilityPreferences()
    {
        if (!auth()->check()) {
            return [];
        }
        
        $user = auth()->user();
        
        return [
            'high_contrast' => $user->accessibility_preferences['high_contrast'] ?? false,
            'large_text' => $user->accessibility_preferences['large_text'] ?? false,
            'reduced_motion' => $user->accessibility_preferences['reduced_motion'] ?? false,
            'screen_reader' => $user->accessibility_preferences['screen_reader'] ?? false
        ];
    }

    /**
     * Update accessibility preferences
     */
    public function updateAccessibilityPreferences(Request $request): JsonResponse
    {
        $request->validate([
            'high_contrast' => 'boolean',
            'large_text' => 'boolean',
            'reduced_motion' => 'boolean',
            'screen_reader' => 'boolean'
        ]);
        
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Kullanıcı girişi gerekli.'
            ], 401);
        }
        
        $user = auth()->user();
        $preferences = $user->accessibility_preferences ?? [];
        
        // Update preferences
        foreach ($request->all() as $key => $value) {
            $preferences[$key] = $value;
        }
        
        $user->update(['accessibility_preferences' => $preferences]);
        
        return response()->json([
            'success' => true,
            'message' => 'Erişilebilirlik tercihleri güncellendi.',
            'preferences' => $preferences
        ]);
    }
} 