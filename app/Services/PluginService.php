<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class PluginService
{
    protected $pluginsPath;
    protected $plugins = [];
    protected $activePlugins = [];
    
    public function __construct()
    {
        $this->pluginsPath = base_path('plugins');
        $this->loadPlugins();
        $this->loadActivePlugins();
    }

    /**
     * Load all available plugins
     */
    private function loadPlugins()
    {
        if (!is_dir($this->pluginsPath)) {
            mkdir($this->pluginsPath, 0755, true);
            return;
        }

        $pluginDirs = File::directories($this->pluginsPath);
        
        foreach ($pluginDirs as $pluginDir) {
            $pluginInfo = $this->getPluginInfo($pluginDir);
            if ($pluginInfo) {
                $this->plugins[basename($pluginDir)] = $pluginInfo;
            }
        }
    }

    /**
     * Load active plugins from database
     */
    private function loadActivePlugins()
    {
        try {
            if (Schema::hasTable('plugins')) {
                $this->activePlugins = \App\Models\Plugin::where('is_active', true)->pluck('name')->toArray();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load active plugins: ' . $e->getMessage());
        }
    }

    /**
     * Get plugin information
     */
    private function getPluginInfo($pluginDir)
    {
        $pluginFile = $pluginDir . '/plugin.json';
        
        if (!file_exists($pluginFile)) {
            return null;
        }

        try {
            $pluginData = json_decode(file_get_contents($pluginFile), true);
            
            if (!$pluginData) {
                return null;
            }

            $pluginData['path'] = $pluginDir;
            $pluginData['installed'] = $this->isPluginInstalled($pluginData['name']);
            $pluginData['active'] = in_array($pluginData['name'], $this->activePlugins);
            $pluginData['version_installed'] = $this->getInstalledVersion($pluginData['name']);

            return $pluginData;

        } catch (\Exception $e) {
            Log::error("Failed to read plugin info from {$pluginFile}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all plugins
     */
    public function getAllPlugins()
    {
        return $this->plugins;
    }

    /**
     * Get active plugins
     */
    public function getActivePlugins()
    {
        return array_filter($this->plugins, function ($plugin) {
            return $plugin['active'];
        });
    }

    /**
     * Get plugin by name
     */
    public function getPlugin($name)
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Install plugin
     */
    public function installPlugin($name)
    {
        $plugin = $this->getPlugin($name);
        
        if (!$plugin) {
            throw new \Exception("Plugin '{$name}' not found");
        }

        if ($plugin['installed']) {
            throw new \Exception("Plugin '{$name}' is already installed");
        }

        try {
            // Run installation script if exists
            $installScript = $plugin['path'] . '/install.php';
            if (file_exists($installScript)) {
                include $installScript;
            }

            // Run database migrations if exists
            $migrationsPath = $plugin['path'] . '/database/migrations';
            if (is_dir($migrationsPath)) {
                $this->runPluginMigrations($migrationsPath);
            }

            // Copy assets if exists
            $assetsPath = $plugin['path'] . '/assets';
            if (is_dir($assetsPath)) {
                $this->copyPluginAssets($name, $assetsPath);
            }

            // Register plugin in database
            $this->registerPlugin($plugin);

            // Clear cache
            Cache::forget('active_plugins');

            Log::info("Plugin '{$name}' installed successfully");

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to install plugin '{$name}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Uninstall plugin
     */
    public function uninstallPlugin($name)
    {
        $plugin = $this->getPlugin($name);
        
        if (!$plugin) {
            throw new \Exception("Plugin '{$name}' not found");
        }

        if (!$plugin['installed']) {
            throw new \Exception("Plugin '{$name}' is not installed");
        }

        try {
            // Run uninstallation script if exists
            $uninstallScript = $plugin['path'] . '/uninstall.php';
            if (file_exists($uninstallScript)) {
                include $uninstallScript;
            }

            // Remove database tables if exists
            $migrationsPath = $plugin['path'] . '/database/migrations';
            if (is_dir($migrationsPath)) {
                $this->rollbackPluginMigrations($migrationsPath);
            }

            // Remove assets
            $this->removePluginAssets($name);

            // Unregister plugin from database
            $this->unregisterPlugin($name);

            // Clear cache
            Cache::forget('active_plugins');

            Log::info("Plugin '{$name}' uninstalled successfully");

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to uninstall plugin '{$name}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Activate plugin
     */
    public function activatePlugin($name)
    {
        $plugin = $this->getPlugin($name);
        
        if (!$plugin) {
            throw new \Exception("Plugin '{$name}' not found");
        }

        if (!$plugin['installed']) {
            throw new \Exception("Plugin '{$name}' must be installed first");
        }

        if ($plugin['active']) {
            throw new \Exception("Plugin '{$name}' is already active");
        }

        try {
            // Run activation script if exists
            $activateScript = $plugin['path'] . '/activate.php';
            if (file_exists($activateScript)) {
                include $activateScript;
            }

            // Update plugin status in database
            $this->updatePluginStatus($name, true);

            // Clear cache
            Cache::forget('active_plugins');

            Log::info("Plugin '{$name}' activated successfully");

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to activate plugin '{$name}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Deactivate plugin
     */
    public function deactivatePlugin($name)
    {
        $plugin = $this->getPlugin($name);
        
        if (!$plugin) {
            throw new \Exception("Plugin '{$name}' not found");
        }

        if (!$plugin['active']) {
            throw new \Exception("Plugin '{$name}' is not active");
        }

        try {
            // Run deactivation script if exists
            $deactivateScript = $plugin['path'] . '/deactivate.php';
            if (file_exists($deactivateScript)) {
                include $deactivateScript;
            }

            // Update plugin status in database
            $this->updatePluginStatus($name, false);

            // Clear cache
            Cache::forget('active_plugins');

            Log::info("Plugin '{$name}' deactivated successfully");

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to deactivate plugin '{$name}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update plugin
     */
    public function updatePlugin($name)
    {
        $plugin = $this->getPlugin($name);
        
        if (!$plugin) {
            throw new \Exception("Plugin '{$name}' not found");
        }

        if (!$plugin['installed']) {
            throw new \Exception("Plugin '{$name}' must be installed first");
        }

        try {
            // Run update script if exists
            $updateScript = $plugin['path'] . '/update.php';
            if (file_exists($updateScript)) {
                include $updateScript;
            }

            // Run new migrations if exists
            $migrationsPath = $plugin['path'] . '/database/migrations';
            if (is_dir($migrationsPath)) {
                $this->runPluginMigrations($migrationsPath);
            }

            // Update plugin version in database
            $this->updatePluginVersion($name, $plugin['version']);

            // Clear cache
            Cache::forget('active_plugins');

            Log::info("Plugin '{$name}' updated successfully");

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to update plugin '{$name}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if plugin is installed
     */
    private function isPluginInstalled($name)
    {
        try {
            if (Schema::hasTable('plugins')) {
                return \App\Models\Plugin::where('name', $name)->exists();
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

    /**
     * Get installed plugin version
     */
    private function getInstalledVersion($name)
    {
        try {
            if (Schema::hasTable('plugins')) {
                $plugin = \App\Models\Plugin::where('name', $name)->first();
                return $plugin ? $plugin->version : null;
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Register plugin in database
     */
    private function registerPlugin($plugin)
    {
        try {
            if (Schema::hasTable('plugins')) {
                \App\Models\Plugin::create([
                    'name' => $plugin['name'],
                    'title' => $plugin['title'],
                    'description' => $plugin['description'],
                    'version' => $plugin['version'],
                    'author' => $plugin['author'],
                    'website' => $plugin['website'] ?? null,
                    'is_active' => false,
                    'installed_at' => now()
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to register plugin in database: " . $e->getMessage());
        }
    }

    /**
     * Unregister plugin from database
     */
    private function unregisterPlugin($name)
    {
        try {
            if (Schema::hasTable('plugins')) {
                \App\Models\Plugin::where('name', $name)->delete();
            }
        } catch (\Exception $e) {
            Log::error("Failed to unregister plugin from database: " . $e->getMessage());
        }
    }

    /**
     * Update plugin status
     */
    private function updatePluginStatus($name, $active)
    {
        try {
            if (Schema::hasTable('plugins')) {
                \App\Models\Plugin::where('name', $name)->update([
                    'is_active' => $active,
                    'activated_at' => $active ? now() : null
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to update plugin status: " . $e->getMessage());
        }
    }

    /**
     * Update plugin version
     */
    private function updatePluginVersion($name, $version)
    {
        try {
            if (Schema::hasTable('plugins')) {
                \App\Models\Plugin::where('name', $name)->update([
                    'version' => $version,
                    'updated_at' => now()
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to update plugin version: " . $e->getMessage());
        }
    }

    /**
     * Run plugin migrations
     */
    private function runPluginMigrations($migrationsPath)
    {
        $migrations = File::files($migrationsPath);
        
        foreach ($migrations as $migration) {
            if (pathinfo($migration, PATHINFO_EXTENSION) === 'php') {
                $this->runMigration($migration);
            }
        }
    }

    /**
     * Rollback plugin migrations
     */
    private function rollbackPluginMigrations($migrationsPath)
    {
        $migrations = File::files($migrationsPath);
        
        foreach ($migrations as $migration) {
            if (pathinfo($migration, PATHINFO_EXTENSION) === 'php') {
                $this->rollbackMigration($migration);
            }
        }
    }

    /**
     * Run single migration
     */
    private function runMigration($migrationFile)
    {
        try {
            $migration = include $migrationFile;
            
            if (method_exists($migration, 'up')) {
                $migration->up();
            }
        } catch (\Exception $e) {
            Log::error("Failed to run migration {$migrationFile}: " . $e->getMessage());
        }
    }

    /**
     * Rollback single migration
     */
    private function rollbackMigration($migrationFile)
    {
        try {
            $migration = include $migrationFile;
            
            if (method_exists($migration, 'down')) {
                $migration->down();
            }
        } catch (\Exception $e) {
            Log::error("Failed to rollback migration {$migrationFile}: " . $e->getMessage());
        }
    }

    /**
     * Copy plugin assets
     */
    private function copyPluginAssets($pluginName, $assetsPath)
    {
        $publicPath = public_path("plugins/{$pluginName}");
        
        if (!is_dir($publicPath)) {
            mkdir($publicPath, 0755, true);
        }

        File::copyDirectory($assetsPath, $publicPath);
    }

    /**
     * Remove plugin assets
     */
    private function removePluginAssets($pluginName)
    {
        $publicPath = public_path("plugins/{$pluginName}");
        
        if (is_dir($publicPath)) {
            File::deleteDirectory($publicPath);
        }
    }

    /**
     * Get plugin hooks
     */
    public function getPluginHooks($hookName)
    {
        $hooks = [];
        
        foreach ($this->getActivePlugins() as $plugin) {
            $hooksFile = $plugin['path'] . '/hooks.php';
            
            if (file_exists($hooksFile)) {
                $pluginHooks = include $hooksFile;
                
                if (isset($pluginHooks[$hookName])) {
                    $hooks[] = $pluginHooks[$hookName];
                }
            }
        }

        return $hooks;
    }

    /**
     * Execute plugin hook
     */
    public function executeHook($hookName, $data = [])
    {
        $hooks = $this->getPluginHooks($hookName);
        $results = [];

        foreach ($hooks as $hook) {
            try {
                if (is_callable($hook)) {
                    $results[] = call_user_func($hook, $data);
                }
            } catch (\Exception $e) {
                Log::error("Plugin hook execution failed: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Get plugin settings
     */
    public function getPluginSettings($pluginName)
    {
        $plugin = $this->getPlugin($pluginName);
        
        if (!$plugin || !$plugin['installed']) {
            return null;
        }

        $settingsFile = $plugin['path'] . '/settings.php';
        
        if (file_exists($settingsFile)) {
            return include $settingsFile;
        }

        return null;
    }

    /**
     * Save plugin settings
     */
    public function savePluginSettings($pluginName, $settings)
    {
        $plugin = $this->getPlugin($pluginName);
        
        if (!$plugin || !$plugin['installed']) {
            throw new \Exception("Plugin '{$pluginName}' not found or not installed");
        }

        try {
            if (Schema::hasTable('plugin_settings')) {
                \App\Models\PluginSetting::updateOrCreate(
                    ['plugin_name' => $pluginName],
                    ['settings' => json_encode($settings), 'updated_at' => now()]
                );
            }
        } catch (\Exception $e) {
            Log::error("Failed to save plugin settings: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get plugin statistics
     */
    public function getPluginStats()
    {
        $stats = [
            'total_plugins' => count($this->plugins),
            'installed_plugins' => count(array_filter($this->plugins, function ($p) { return $p['installed']; })),
            'active_plugins' => count(array_filter($this->plugins, function ($p) { return $p['active']; })),
            'plugins_by_category' => [],
            'plugins_by_author' => []
        ];

        foreach ($this->plugins as $plugin) {
            // Count by category
            $category = $plugin['category'] ?? 'Uncategorized';
            $stats['plugins_by_category'][$category] = ($stats['plugins_by_category'][$category] ?? 0) + 1;

            // Count by author
            $author = $plugin['author'] ?? 'Unknown';
            $stats['plugins_by_author'][$author] = ($stats['plugins_by_author'][$author] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Check plugin compatibility
     */
    public function checkPluginCompatibility($pluginName)
    {
        $plugin = $this->getPlugin($pluginName);
        
        if (!$plugin) {
            return ['compatible' => false, 'reason' => 'Plugin not found'];
        }

        $requirements = $plugin['requirements'] ?? [];
        $compatibility = ['compatible' => true, 'issues' => []];

        // Check PHP version
        if (isset($requirements['php'])) {
            if (version_compare(PHP_VERSION, $requirements['php'], '<')) {
                $compatibility['compatible'] = false;
                $compatibility['issues'][] = "PHP version {$requirements['php']} or higher required";
            }
        }

        // Check Laravel version
        if (isset($requirements['laravel'])) {
            $laravelVersion = app()->version();
            if (version_compare($laravelVersion, $requirements['laravel'], '<')) {
                $compatibility['compatible'] = false;
                $compatibility['issues'][] = "Laravel version {$requirements['laravel']} or higher required";
            }
        }

        // Check extensions
        if (isset($requirements['extensions'])) {
            foreach ($requirements['extensions'] as $extension) {
                if (!extension_loaded($extension)) {
                    $compatibility['compatible'] = false;
                    $compatibility['issues'][] = "PHP extension '{$extension}' required";
                }
            }
        }

        return $compatibility;
    }

    /**
     * Validate plugin
     */
    public function validatePlugin($pluginName)
    {
        $plugin = $this->getPlugin($pluginName);
        
        if (!$plugin) {
            return ['valid' => false, 'errors' => ['Plugin not found']];
        }

        $errors = [];

        // Check required fields
        $requiredFields = ['name', 'title', 'version', 'author'];
        foreach ($requiredFields as $field) {
            if (empty($plugin[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Check version format
        if (!preg_match('/^\d+\.\d+\.\d+$/', $plugin['version'])) {
            $errors[] = "Invalid version format. Use semantic versioning (e.g., 1.0.0)";
        }

        // Check plugin structure
        $requiredFiles = ['plugin.json'];
        foreach ($requiredFiles as $file) {
            if (!file_exists($plugin['path'] . '/' . $file)) {
                $errors[] = "Missing required file: {$file}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
} 