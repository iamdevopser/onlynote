<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;

class ThemeService
{
    const THEME_COOKIE_NAME = 'lms_theme';
    const THEME_CACHE_KEY = 'user_theme_';
    
    const AVAILABLE_THEMES = [
        'light' => [
            'name' => 'Açık Tema',
            'icon' => 'sun',
            'description' => 'Parlak ve temiz görünüm'
        ],
        'dark' => [
            'name' => 'Koyu Tema',
            'icon' => 'moon',
            'description' => 'Göz yormayan koyu görünüm'
        ],
        'auto' => [
            'name' => 'Otomatik',
            'icon' => 'monitor',
            'description' => 'Sistem ayarına göre'
        ]
    ];

    /**
     * Get current theme for user
     */
    public function getCurrentTheme($userId = null)
    {
        if ($userId) {
            return Cache::get(self::THEME_CACHE_KEY . $userId, 'light');
        }
        
        return Cookie::get(self::THEME_COOKIE_NAME, 'light');
    }

    /**
     * Set theme for user
     */
    public function setTheme($theme, $userId = null)
    {
        if (!array_key_exists($theme, self::AVAILABLE_THEMES)) {
            $theme = 'light';
        }

        if ($userId) {
            Cache::put(self::THEME_CACHE_KEY . $userId, $theme, 60 * 24 * 30); // 30 days
        }
        
        Cookie::queue(self::THEME_COOKIE_NAME, $theme, 60 * 24 * 30); // 30 days
        
        return $theme;
    }

    /**
     * Get theme CSS variables
     */
    public function getThemeVariables($theme = null)
    {
        if (!$theme) {
            $theme = $this->getCurrentTheme();
        }

        $variables = [
            'light' => [
                '--primary-color' => '#667eea',
                '--secondary-color' => '#764ba2',
                '--success-color' => '#28a745',
                '--danger-color' => '#dc3545',
                '--warning-color' => '#ffc107',
                '--info-color' => '#17a2b8',
                '--light-color' => '#f8f9fa',
                '--dark-color' => '#343a40',
                '--body-bg' => '#ffffff',
                '--body-color' => '#212529',
                '--card-bg' => '#ffffff',
                '--card-border' => '#dee2e6',
                '--input-bg' => '#ffffff',
                '--input-border' => '#ced4da',
                '--input-color' => '#495057',
                '--sidebar-bg' => '#f8f9fa',
                '--sidebar-color' => '#495057',
                '--header-bg' => '#ffffff',
                '--header-color' => '#212529',
                '--footer-bg' => '#f8f9fa',
                '--footer-color' => '#6c757d',
                '--shadow' => '0 0.125rem 0.25rem rgba(0, 0, 0, 0.075)',
                '--shadow-lg' => '0 1rem 3rem rgba(0, 0, 0, 0.175)',
                '--border-radius' => '0.375rem',
                '--transition' => 'all 0.15s ease-in-out'
            ],
            'dark' => [
                '--primary-color' => '#667eea',
                '--secondary-color' => '#764ba2',
                '--success-color' => '#28a745',
                '--danger-color' => '#dc3545',
                '--warning-color' => '#ffc107',
                '--info-color' => '#17a2b8',
                '--light-color' => '#495057',
                '--dark-color' => '#f8f9fa',
                '--body-bg' => '#1a1a1a',
                '--body-color' => '#e9ecef',
                '--card-bg' => '#2d2d2d',
                '--card-border' => '#404040',
                '--input-bg' => '#2d2d2d',
                '--input-border' => '#404040',
                '--input-color' => '#e9ecef',
                '--sidebar-bg' => '#2d2d2d',
                '--sidebar-color' => '#e9ecef',
                '--header-bg' => '#2d2d2d',
                '--header-color' => '#e9ecef',
                '--footer-bg' => '#2d2d2d',
                '--footer-color' => '#adb5bd',
                '--shadow' => '0 0.125rem 0.25rem rgba(0, 0, 0, 0.3)',
                '--shadow-lg' => '0 1rem 3rem rgba(0, 0, 0, 0.4)',
                '--border-radius' => '0.375rem',
                '--transition' => 'all 0.15s ease-in-out'
            ]
        ];

        return $variables[$theme] ?? $variables['light'];
    }

    /**
     * Get theme CSS
     */
    public function getThemeCSS($theme = null)
    {
        $variables = $this->getThemeVariables($theme);
        $css = ':root {';
        
        foreach ($variables as $property => $value) {
            $css .= "\n    {$property}: {$value};";
        }
        
        $css .= "\n}";
        
        return $css;
    }

    /**
     * Check if system prefers dark mode
     */
    public function systemPrefersDark()
    {
        if (isset($_SERVER['HTTP_SEC_CH_UA_COLOR_SCHEME'])) {
            return $_SERVER['HTTP_SEC_CH_UA_COLOR_SCHEME'] === 'dark';
        }
        
        return false;
    }

    /**
     * Get effective theme (considering auto mode)
     */
    public function getEffectiveTheme($theme = null)
    {
        if (!$theme) {
            $theme = $this->getCurrentTheme();
        }
        
        if ($theme === 'auto') {
            return $this->systemPrefersDark() ? 'dark' : 'light';
        }
        
        return $theme;
    }

    /**
     * Get available themes
     */
    public function getAvailableThemes()
    {
        return self::AVAILABLE_THEMES;
    }

    /**
     * Reset user theme preferences
     */
    public function resetTheme($userId = null)
    {
        if ($userId) {
            Cache::forget(self::THEME_CACHE_KEY . $userId);
        }
        
        Cookie::queue(Cookie::forget(self::THEME_COOKIE_NAME));
        
        return 'light';
    }
} 