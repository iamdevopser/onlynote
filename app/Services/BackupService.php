<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

class BackupService
{
    protected $backupPath;
    protected $maxBackups;
    protected $compressionEnabled;
    protected $encryptionEnabled;
    
    public function __construct()
    {
        $this->backupPath = config('backup.path', storage_path('backups'));
        $this->maxBackups = config('backup.max_backups', 30);
        $this->compressionEnabled = config('backup.compression', true);
        $this->encryptionEnabled = config('backup.encryption', false);
        
        // Create backup directory if it doesn't exist
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    /**
     * Create full system backup
     */
    public function createFullBackup($options = [])
    {
        try {
            $backupId = 'full_backup_' . now()->format('Y-m-d_H-i-s');
            $backupDir = $this->backupPath . '/' . $backupId;
            
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $backupInfo = [
                'id' => $backupId,
                'type' => 'full',
                'started_at' => now(),
                'components' => []
            ];

            // Backup database
            $backupInfo['components']['database'] = $this->backupDatabase($backupDir);

            // Backup files
            $backupInfo['components']['files'] = $this->backupFiles($backupDir);

            // Backup configuration
            $backupInfo['components']['config'] = $this->backupConfiguration($backupDir);

            // Backup logs
            $backupInfo['components']['logs'] = $this->backupLogs($backupDir);

            // Create backup manifest
            $backupInfo['completed_at'] = now();
            $backupInfo['duration'] = $backupInfo['started_at']->diffInSeconds($backupInfo['completed_at']);
            $backupInfo['total_size'] = $this->calculateBackupSize($backupDir);
            
            $this->createBackupManifest($backupDir, $backupInfo);

            // Compress backup if enabled
            if ($this->compressionEnabled) {
                $backupInfo['compressed'] = $this->compressBackup($backupDir);
            }

            // Encrypt backup if enabled
            if ($this->encryptionEnabled) {
                $backupInfo['encrypted'] = $this->encryptBackup($backupDir);
            }

            // Clean old backups
            $this->cleanOldBackups();

            // Log backup completion
            Log::info('Full backup completed successfully', $backupInfo);

            return $backupInfo;

        } catch (\Exception $e) {
            Log::error('Full backup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create database backup
     */
    public function createDatabaseBackup($options = [])
    {
        try {
            $backupId = 'db_backup_' . now()->format('Y-m-d_H-i-s');
            $backupDir = $this->backupPath . '/' . $backupId;
            
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $backupInfo = [
                'id' => $backupId,
                'type' => 'database',
                'started_at' => now()
            ];

            // Backup database
            $backupInfo['database'] = $this->backupDatabase($backupDir);

            // Create backup manifest
            $backupInfo['completed_at'] = now();
            $backupInfo['duration'] = $backupInfo['started_at']->diffInSeconds($backupInfo['completed_at']);
            $backupInfo['total_size'] = $this->calculateBackupSize($backupDir);
            
            $this->createBackupManifest($backupDir, $backupInfo);

            // Compress backup if enabled
            if ($this->compressionEnabled) {
                $backupInfo['compressed'] = $this->compressBackup($backupDir);
            }

            // Clean old backups
            $this->cleanOldBackups();

            Log::info('Database backup completed successfully', $backupInfo);

            return $backupInfo;

        } catch (\Exception $e) {
            Log::error('Database backup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create files backup
     */
    public function createFilesBackup($options = [])
    {
        try {
            $backupId = 'files_backup_' . now()->format('Y-m-d_H-i-s');
            $backupDir = $this->backupPath . '/' . $backupId;
            
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $backupInfo = [
                'id' => $backupId,
                'type' => 'files',
                'started_at' => now()
            ];

            // Backup files
            $backupInfo['files'] = $this->backupFiles($backupDir);

            // Create backup manifest
            $backupInfo['completed_at'] = now();
            $backupInfo['duration'] = $backupInfo['started_at']->diffInSeconds($backupInfo['completed_at']);
            $backupInfo['total_size'] = $this->calculateBackupSize($backupDir);
            
            $this->createBackupManifest($backupDir, $backupInfo);

            // Compress backup if enabled
            if ($this->compressionEnabled) {
                $backupInfo['compressed'] = $this->compressBackup($backupDir);
            }

            // Clean old backups
            $this->cleanOldBackups();

            Log::info('Files backup completed successfully', $backupInfo);

            return $backupInfo;

        } catch (\Exception $e) {
            Log::error('Files backup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Backup database
     */
    private function backupDatabase($backupDir)
    {
        $dbBackupPath = $backupDir . '/database.sql';
        
        try {
            $connection = config('database.default');
            $database = config("database.connections.{$connection}.database");
            $username = config("database.connections.{$connection}.username");
            $password = config("database.connections.{$connection}.password");
            $host = config("database.connections.{$connection}.host");
            $port = config("database.connections.{$connection}.port");

            $command = "mysqldump --host={$host} --port={$port} --user={$username} --password={$password} {$database} > {$dbBackupPath}";

            $result = Process::run($command);

            if ($result->successful()) {
                return [
                    'path' => $dbBackupPath,
                    'size' => filesize($dbBackupPath),
                    'tables' => $this->getDatabaseTableCount(),
                    'status' => 'success'
                ];
            } else {
                throw new \Exception('Database dump failed: ' . $result->errorOutput());
            }

        } catch (\Exception $e) {
            Log::error('Database backup failed: ' . $e->getMessage());
            
            return [
                'path' => null,
                'size' => 0,
                'tables' => 0,
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Backup files
     */
    private function backupFiles($backupDir)
    {
        $filesBackupPath = $backupDir . '/files';
        
        if (!is_dir($filesBackupPath)) {
            mkdir($filesBackupPath, 0755, true);
        }

        $backupStats = [
            'uploaded_files' => 0,
            'course_files' => 0,
            'user_files' => 0,
            'total_size' => 0
        ];

        try {
            // Backup uploaded files
            $uploadPath = storage_path('app/upload');
            if (is_dir($uploadPath)) {
                $this->copyDirectory($uploadPath, $filesBackupPath . '/upload');
                $backupStats['uploaded_files'] = $this->countFiles($uploadPath);
                $backupStats['total_size'] += $this->calculateDirectorySize($uploadPath);
            }

            // Backup course files
            $coursePath = storage_path('app/courses');
            if (is_dir($coursePath)) {
                $this->copyDirectory($coursePath, $filesBackupPath . '/courses');
                $backupStats['course_files'] = $this->countFiles($coursePath);
                $backupStats['total_size'] += $this->calculateDirectorySize($coursePath);
            }

            // Backup user files
            $userPath = storage_path('app/users');
            if (is_dir($userPath)) {
                $this->copyDirectory($userPath, $filesBackupPath . '/users');
                $backupStats['user_files'] = $this->countFiles($userPath);
                $backupStats['total_size'] += $this->calculateDirectorySize($userPath);
            }

            $backupStats['status'] = 'success';

        } catch (\Exception $e) {
            Log::error('Files backup failed: ' . $e->getMessage());
            $backupStats['status'] = 'failed';
            $backupStats['error'] = $e->getMessage();
        }

        return $backupStats;
    }

    /**
     * Backup configuration
     */
    private function backupConfiguration($backupDir)
    {
        $configBackupPath = $backupDir . '/config';
        
        if (!is_dir($configBackupPath)) {
            mkdir($configBackupPath, 0755, true);
        }

        try {
            // Backup .env file
            $envPath = base_path('.env');
            if (file_exists($envPath)) {
                copy($envPath, $configBackupPath . '/.env');
            }

            // Backup config files
            $configPath = base_path('config');
            if (is_dir($configPath)) {
                $this->copyDirectory($configPath, $configBackupPath . '/config');
            }

            // Backup composer files
            $composerPath = base_path('composer.json');
            $composerLockPath = base_path('composer.lock');
            
            if (file_exists($composerPath)) {
                copy($composerPath, $configBackupPath . '/composer.json');
            }
            
            if (file_exists($composerLockPath)) {
                copy($composerLockPath, $configBackupPath . '/composer.lock');
            }

            return [
                'status' => 'success',
                'files_backed_up' => $this->countFiles($configBackupPath)
            ];

        } catch (\Exception $e) {
            Log::error('Configuration backup failed: ' . $e->getMessage());
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Backup logs
     */
    private function backupLogs($backupDir)
    {
        $logsBackupPath = $backupDir . '/logs';
        
        if (!is_dir($logsBackupPath)) {
            mkdir($logsBackupPath, 0755, true);
        }

        try {
            $logsPath = storage_path('logs');
            if (is_dir($logsPath)) {
                $this->copyDirectory($logsPath, $logsBackupPath);
                
                return [
                    'status' => 'success',
                    'files_backed_up' => $this->countFiles($logsPath),
                    'total_size' => $this->calculateDirectorySize($logsPath)
                ];
            }

            return [
                'status' => 'skipped',
                'message' => 'No logs directory found'
            ];

        } catch (\Exception $e) {
            Log::error('Logs backup failed: ' . $e->getMessage());
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create backup manifest
     */
    private function createBackupManifest($backupDir, $backupInfo)
    {
        $manifestPath = $backupDir . '/manifest.json';
        file_put_contents($manifestPath, json_encode($backupInfo, JSON_PRETTY_PRINT));
    }

    /**
     * Compress backup
     */
    private function compressBackup($backupDir)
    {
        try {
            $archivePath = $backupDir . '.tar.gz';
            
            $command = "tar -czf \"{$archivePath}\" -C \"" . dirname($backupDir) . "\" " . basename($backupDir);
            
            $result = Process::run($command);

            if ($result->successful()) {
                // Remove uncompressed backup directory
                $this->removeDirectory($backupDir);
                
                return [
                    'status' => 'success',
                    'archive_path' => $archivePath,
                    'compressed_size' => filesize($archivePath),
                    'compression_ratio' => $this->calculateCompressionRatio($backupDir, $archivePath)
                ];
            } else {
                throw new \Exception('Compression failed: ' . $result->errorOutput());
            }

        } catch (\Exception $e) {
            Log::error('Backup compression failed: ' . $e->getMessage());
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Encrypt backup
     */
    private function encryptBackup($backupDir)
    {
        try {
            $encryptionKey = config('backup.encryption_key', config('app.key'));
            
            if (!$encryptionKey) {
                throw new \Exception('Encryption key not configured');
            }

            $encryptedPath = $backupDir . '.enc';
            
            // Simple encryption using openssl
            $command = "openssl enc -aes-256-cbc -salt -in \"{$backupDir}\" -out \"{$encryptedPath}\" -pass pass:{$encryptionKey}";
            
            $result = Process::run($command);

            if ($result->successful()) {
                // Remove unencrypted backup
                $this->removeDirectory($backupDir);
                
                return [
                    'status' => 'success',
                    'encrypted_path' => $encryptedPath,
                    'encrypted_size' => filesize($encryptedPath)
                ];
            } else {
                throw new \Exception('Encryption failed: ' . $result->errorOutput());
            }

        } catch (\Exception $e) {
            Log::error('Backup encryption failed: ' . $e->getMessage());
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Restore backup
     */
    public function restoreBackup($backupPath, $options = [])
    {
        try {
            $restoreInfo = [
                'backup_path' => $backupPath,
                'started_at' => now(),
                'components' => []
            ];

            // Extract backup if compressed
            if (pathinfo($backupPath, PATHINFO_EXTENSION) === 'gz') {
                $extractedPath = $this->extractBackup($backupPath);
                $backupPath = $extractedPath;
            }

            // Read backup manifest
            $manifestPath = $backupPath . '/manifest.json';
            if (!file_exists($manifestPath)) {
                throw new \Exception('Backup manifest not found');
            }

            $manifest = json_decode(file_get_contents($manifestPath), true);

            // Restore components based on backup type
            if ($manifest['type'] === 'full' || $manifest['type'] === 'database') {
                $restoreInfo['components']['database'] = $this->restoreDatabase($backupPath);
            }

            if ($manifest['type'] === 'full' || $manifest['type'] === 'files') {
                $restoreInfo['components']['files'] = $this->restoreFiles($backupPath);
            }

            if ($manifest['type'] === 'full') {
                $restoreInfo['components']['config'] = $this->restoreConfiguration($backupPath);
                $restoreInfo['components']['logs'] = $this->restoreLogs($backupPath);
            }

            $restoreInfo['completed_at'] = now();
            $restoreInfo['duration'] = $restoreInfo['started_at']->diffInSeconds($restoreInfo['completed_at']);

            Log::info('Backup restored successfully', $restoreInfo);

            return $restoreInfo;

        } catch (\Exception $e) {
            Log::error('Backup restore failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract compressed backup
     */
    private function extractBackup($archivePath)
    {
        $extractDir = dirname($archivePath) . '/extracted_' . time();
        
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        $command = "tar -xzf \"{$archivePath}\" -C \"{$extractDir}\"";
        
        $result = Process::run($command);

        if (!$result->successful()) {
            throw new \Exception('Failed to extract backup: ' . $result->errorOutput());
        }

        // Find extracted directory
        $extractedDirs = glob($extractDir . '/*', GLOB_ONLYDIR);
        
        if (empty($extractedDirs)) {
            throw new \Exception('No backup directory found in archive');
        }

        return $extractedDirs[0];
    }

    /**
     * Restore database
     */
    private function restoreDatabase($backupPath)
    {
        $dbBackupPath = $backupPath . '/database.sql';
        
        if (!file_exists($dbBackupPath)) {
            return ['status' => 'skipped', 'message' => 'Database backup not found'];
        }

        try {
            $connection = config('database.default');
            $database = config("database.connections.{$connection}.database");
            $username = config("database.connections.{$connection}.username");
            $password = config("database.connections.{$connection}.password");
            $host = config("database.connections.{$connection}.host");
            $port = config("database.connections.{$connection}.port");

            $command = "mysql --host={$host} --port={$port} --user={$username} --password={$password} {$database} < {$dbBackupPath}";

            $result = Process::run($command);

            if ($result->successful()) {
                return [
                    'status' => 'success',
                    'restored_tables' => $this->getDatabaseTableCount()
                ];
            } else {
                throw new \Exception('Database restore failed: ' . $result->errorOutput());
            }

        } catch (\Exception $e) {
            Log::error('Database restore failed: ' . $e->getMessage());
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Restore files
     */
    private function restoreFiles($backupPath)
    {
        $filesBackupPath = $backupPath . '/files';
        
        if (!is_dir($filesBackupPath)) {
            return ['status' => 'skipped', 'message' => 'Files backup not found'];
        }

        try {
            // Restore uploaded files
            $uploadBackupPath = $filesBackupPath . '/upload';
            if (is_dir($uploadBackupPath)) {
                $uploadPath = storage_path('app/upload');
                $this->copyDirectory($uploadBackupPath, $uploadPath);
            }

            // Restore course files
            $courseBackupPath = $filesBackupPath . '/courses';
            if (is_dir($courseBackupPath)) {
                $coursePath = storage_path('app/courses');
                $this->copyDirectory($courseBackupPath, $coursePath);
            }

            // Restore user files
            $userBackupPath = $filesBackupPath . '/users';
            if (is_dir($userBackupPath)) {
                $userPath = storage_path('app/users');
                $this->copyDirectory($userBackupPath, $userPath);
            }

            return ['status' => 'success'];

        } catch (\Exception $e) {
            Log::error('Files restore failed: ' . $e->getMessage());
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Restore configuration
     */
    private function restoreConfiguration($backupPath)
    {
        $configBackupPath = $backupPath . '/config';
        
        if (!is_dir($configBackupPath)) {
            return ['status' => 'skipped', 'message' => 'Configuration backup not found'];
        }

        try {
            // Restore .env file
            $envBackupPath = $configBackupPath . '/.env';
            if (file_exists($envBackupPath)) {
                copy($envBackupPath, base_path('.env'));
            }

            return ['status' => 'success'];

        } catch (\Exception $e) {
            Log::error('Configuration restore failed: ' . $e->getMessage());
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Restore logs
     */
    private function restoreLogs($backupPath)
    {
        $logsBackupPath = $backupPath . '/logs';
        
        if (!is_dir($logsBackupPath)) {
            return ['status' => 'skipped', 'message' => 'Logs backup not found'];
        }

        try {
            $logsPath = storage_path('logs');
            $this->copyDirectory($logsBackupPath, $logsPath);
            
            return ['status' => 'success'];

        } catch (\Exception $e) {
            Log::error('Logs restore failed: ' . $e->getMessage());
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Clean old backups
     */
    private function cleanOldBackups()
    {
        $backups = glob($this->backupPath . '/*', GLOB_ONLYDIR);
        $backups = array_merge($backups, glob($this->backupPath . '/*.tar.gz'));
        $backups = array_merge($backups, glob($this->backupPath . '/*.enc'));

        // Sort by modification time (oldest first)
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        // Remove old backups if we exceed the limit
        if (count($backups) > $this->maxBackups) {
            $toRemove = array_slice($backups, 0, count($backups) - $this->maxBackups);
            
            foreach ($toRemove as $backup) {
                $this->removeBackup($backup);
            }

            Log::info('Old backups cleaned', ['removed_count' => count($toRemove)]);
        }
    }

    /**
     * Remove backup
     */
    private function removeBackup($backupPath)
    {
        if (is_dir($backupPath)) {
            $this->removeDirectory($backupPath);
        } else {
            unlink($backupPath);
        }
    }

    /**
     * Get backup list
     */
    public function getBackupList()
    {
        $backups = [];
        
        $backupDirs = glob($this->backupPath . '/*', GLOB_ONLYDIR);
        $backupFiles = array_merge(
            glob($this->backupPath . '/*.tar.gz'),
            glob($this->backupPath . '/*.enc')
        );

        foreach ($backupDirs as $backupDir) {
            $manifestPath = $backupDir . '/manifest.json';
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                $backups[] = [
                    'path' => $backupDir,
                    'type' => 'directory',
                    'manifest' => $manifest,
                    'size' => $this->calculateDirectorySize($backupDir),
                    'modified_at' => filemtime($backupDir)
                ];
            }
        }

        foreach ($backupFiles as $backupFile) {
            $backups[] = [
                'path' => $backupFile,
                'type' => 'file',
                'size' => filesize($backupFile),
                'modified_at' => filemtime($backupFile)
            ];
        }

        // Sort by modification time (newest first)
        usort($backups, function($a, $b) {
            return $b['modified_at'] - $a['modified_at'];
        });

        return $backups;
    }

    /**
     * Get backup statistics
     */
    public function getBackupStats()
    {
        $backups = $this->getBackupList();
        
        $stats = [
            'total_backups' => count($backups),
            'total_size' => 0,
            'by_type' => [
                'full' => 0,
                'database' => 0,
                'files' => 0
            ],
            'recent_backups' => 0,
            'oldest_backup' => null,
            'newest_backup' => null
        ];

        foreach ($backups as $backup) {
            $stats['total_size'] += $backup['size'];
            
            if ($backup['type'] === 'directory' && isset($backup['manifest']['type'])) {
                $stats['by_type'][$backup['manifest']['type']]++;
            }
            
            if ($backup['modified_at'] > now()->subDays(7)->timestamp) {
                $stats['recent_backups']++;
            }
            
            if (!$stats['oldest_backup'] || $backup['modified_at'] < $stats['oldest_backup']) {
                $stats['oldest_backup'] = $backup['modified_at'];
            }
            
            if (!$stats['newest_backup'] || $backup['modified_at'] > $stats['newest_backup']) {
                $stats['newest_backup'] = $backup['modified_at'];
            }
        }

        return $stats;
    }

    /**
     * Helper methods
     */
    private function copyDirectory($source, $destination)
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                mkdir($destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            } else {
                copy($item, $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            }
        }
    }

    private function removeDirectory($directory)
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }

    private function countFiles($directory)
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    private function calculateDirectorySize($directory)
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    private function calculateBackupSize($backupDir)
    {
        return $this->calculateDirectorySize($backupDir);
    }

    private function calculateCompressionRatio($originalDir, $compressedFile)
    {
        $originalSize = $this->calculateDirectorySize($originalDir);
        $compressedSize = filesize($compressedFile);
        
        if ($originalSize === 0) {
            return 0;
        }
        
        return round((($originalSize - $compressedSize) / $originalSize) * 100, 2);
    }

    private function getDatabaseTableCount()
    {
        try {
            return DB::select('SHOW TABLES')->count();
        } catch (\Exception $e) {
            return 0;
        }
    }
} 