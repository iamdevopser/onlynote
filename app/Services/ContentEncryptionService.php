<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use App\Models\CourseContent;
use App\Models\EncryptionKey;
use App\Models\User;
use App\Models\CourseEnrollment;

class ContentEncryptionService
{
    protected $encryptionAlgorithms = [
        'AES-256-CBC' => 'AES-256-CBC',
        'AES-256-GCM' => 'AES-256-GCM',
        'ChaCha20-Poly1305' => 'ChaCha20-Poly1305'
    ];

    protected $keyDerivationFunctions = [
        'PBKDF2' => 'PBKDF2',
        'Argon2' => 'Argon2',
        'bcrypt' => 'bcrypt'
    ];

    protected $defaultAlgorithm = 'AES-256-CBC';
    protected $defaultKeyDerivation = 'PBKDF2';

    /**
     * Encrypt content
     */
    public function encryptContent($contentId, $encryptionOptions = [])
    {
        try {
            $content = CourseContent::find($contentId);
            if (!$content) {
                return [
                    'success' => false,
                    'message' => 'Content not found'
                ];
            }

            // Generate encryption key
            $encryptionKey = $this->generateEncryptionKey($encryptionOptions);

            // Read file content
            $fileContent = $this->readFileContent($content->file_path);
            if (!$fileContent) {
                return [
                    'success' => false,
                    'message' => 'Failed to read file content'
                ];
            }

            // Encrypt content
            $encryptedContent = $this->encryptData($fileContent, $encryptionKey, $encryptionOptions);

            // Store encrypted content
            $encryptedFilePath = $this->storeEncryptedContent($encryptedContent, $content->course_id, $content->id);

            // Update content record
            $content->encrypted_file_path = $encryptedFilePath;
            $content->encryption_metadata = [
                'algorithm' => $encryptionOptions['algorithm'] ?? $this->defaultAlgorithm,
                'key_derivation' => $encryptionOptions['key_derivation'] ?? $this->defaultKeyDerivation,
                'encrypted_at' => now()->toISOString(),
                'key_id' => $encryptionKey->id,
                'iv' => $encryptedContent['iv'],
                'tag' => $encryptedContent['tag'] ?? null,
                'salt' => $encryptedContent['salt']
            ];
            $content->is_encrypted = true;
            $content->save();

            // Store encryption key
            $this->storeEncryptionKey($encryptionKey, $content->id);

            Log::info("Content encrypted successfully", [
                'content_id' => $contentId,
                'algorithm' => $encryptionOptions['algorithm'] ?? $this->defaultAlgorithm,
                'key_id' => $encryptionKey->id
            ]);

            return [
                'success' => true,
                'content' => $content,
                'encryption_key' => $encryptionKey,
                'message' => 'Content encrypted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to encrypt content: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to encrypt content: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Decrypt content
     */
    public function decryptContent($contentId, $userId = null)
    {
        try {
            $content = CourseContent::find($contentId);
            if (!$content) {
                return [
                    'success' => false,
                    'message' => 'Content not found'
                ];
            }

            if (!$content->is_encrypted) {
                return [
                    'success' => false,
                    'message' => 'Content is not encrypted'
                ];
            }

            // Check user permissions
            if ($userId && !$this->canAccessContent($content, $userId)) {
                return [
                    'success' => false,
                    'message' => 'Access denied to encrypted content'
                ];
            }

            // Get encryption key
            $encryptionKey = $this->getEncryptionKey($content->id);
            if (!$encryptionKey) {
                return [
                    'success' => false,
                    'message' => 'Encryption key not found'
                ];
            }

            // Read encrypted file
            $encryptedContent = $this->readFileContent($content->encrypted_file_path);
            if (!$encryptedContent) {
                return [
                    'success' => false,
                    'message' => 'Failed to read encrypted file'
                ];
            }

            // Decrypt content
            $decryptedContent = $this->decryptData($encryptedContent, $encryptionKey, $content->encryption_metadata);

            if (!$decryptedContent) {
                return [
                    'success' => false,
                    'message' => 'Failed to decrypt content'
                ];
            }

            // Log access
            $this->logContentAccess($content->id, $userId, 'decrypt');

            return [
                'success' => true,
                'content' => $decryptedContent,
                'message' => 'Content decrypted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to decrypt content: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to decrypt content: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate encryption key
     */
    private function generateEncryptionKey($options = [])
    {
        $algorithm = $options['algorithm'] ?? $this->defaultAlgorithm;
        $keyDerivation = $options['key_derivation'] ?? $this->defaultKeyDerivation;

        // Generate random key
        $key = random_bytes(32); // 256 bits
        $salt = random_bytes(32);

        // Derive key using specified function
        $derivedKey = $this->deriveKey($key, $salt, $keyDerivation, $options);

        // Create encryption key record
        $encryptionKey = EncryptionKey::create([
            'key_hash' => hash('sha256', $derivedKey),
            'algorithm' => $algorithm,
            'key_derivation' => $keyDerivation,
            'salt' => $salt,
            'iterations' => $options['iterations'] ?? 10000,
            'key_length' => strlen($derivedKey),
            'created_at' => now(),
            'expires_at' => isset($options['expires_at']) ? $options['expires_at'] : now()->addYears(10)
        ]);

        // Store the actual key securely (in production, use a key management service)
        $encryptionKey->actual_key = $derivedKey;
        $encryptionKey->save();

        return $encryptionKey;
    }

    /**
     * Derive key using specified function
     */
    private function deriveKey($key, $salt, $keyDerivation, $options = [])
    {
        $iterations = $options['iterations'] ?? 10000;
        $keyLength = $options['key_length'] ?? 32;

        switch ($keyDerivation) {
            case 'PBKDF2':
                return hash_pbkdf2('sha256', $key, $salt, $iterations, $keyLength, true);

            case 'Argon2':
                if (function_exists('sodium_crypto_pwhash')) {
                    return sodium_crypto_pwhash(
                        $keyLength,
                        $key,
                        $salt,
                        $iterations,
                        SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
                    );
                }
                // Fallback to PBKDF2 if Argon2 not available
                return hash_pbkdf2('sha256', $key, $salt, $iterations, $keyLength, true);

            case 'bcrypt':
                return hash_pbkdf2('sha256', $key, $salt, $iterations, $keyLength, true);

            default:
                return hash_pbkdf2('sha256', $key, $salt, $iterations, $keyLength, true);
        }
    }

    /**
     * Encrypt data
     */
    private function encryptData($data, $encryptionKey, $options = [])
    {
        $algorithm = $options['algorithm'] ?? $this->defaultAlgorithm;
        $key = $encryptionKey->actual_key;
        $salt = $encryptionKey->salt;

        switch ($algorithm) {
            case 'AES-256-CBC':
                return $this->encryptAES256CBC($data, $key, $salt);

            case 'AES-256-GCM':
                return $this->encryptAES256GCM($data, $key, $salt);

            case 'ChaCha20-Poly1305':
                return $this->encryptChaCha20Poly1305($data, $key, $salt);

            default:
                return $this->encryptAES256CBC($data, $key, $salt);
        }
    }

    /**
     * Encrypt using AES-256-CBC
     */
    private function encryptAES256CBC($data, $key, $salt)
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \Exception('AES-256-CBC encryption failed');
        }

        return [
            'data' => $encrypted,
            'iv' => base64_encode($iv),
            'salt' => base64_encode($salt),
            'algorithm' => 'AES-256-CBC'
        ];
    }

    /**
     * Encrypt using AES-256-GCM
     */
    private function encryptAES256GCM($data, $key, $salt)
    {
        $iv = random_bytes(12);
        $tag = '';
        
        $encrypted = openssl_encrypt($data, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($encrypted === false) {
            throw new \Exception('AES-256-GCM encryption failed');
        }

        return [
            'data' => $encrypted,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'salt' => base64_encode($salt),
            'algorithm' => 'AES-256-GCM'
        ];
    }

    /**
     * Encrypt using ChaCha20-Poly1305
     */
    private function encryptChaCha20Poly1305($data, $key, $salt)
    {
        if (!function_exists('sodium_crypto_aead_chacha20poly1305_encrypt')) {
            throw new \Exception('ChaCha20-Poly1305 not available');
        }

        $nonce = random_bytes(24);
        $encrypted = sodium_crypto_aead_chacha20poly1305_encrypt($data, '', $nonce, $key);

        return [
            'data' => $encrypted,
            'iv' => base64_encode($nonce),
            'salt' => base64_encode($salt),
            'algorithm' => 'ChaCha20-Poly1305'
        ];
    }

    /**
     * Decrypt data
     */
    private function decryptData($encryptedData, $encryptionKey, $metadata)
    {
        $algorithm = $metadata['algorithm'];
        $key = $encryptionKey->actual_key;
        $iv = base64_decode($metadata['iv']);
        $salt = base64_decode($metadata['salt']);
        $tag = isset($metadata['tag']) ? base64_decode($metadata['tag']) : null;

        switch ($algorithm) {
            case 'AES-256-CBC':
                return $this->decryptAES256CBC($encryptedData, $key, $iv);

            case 'AES-256-GCM':
                return $this->decryptAES256GCM($encryptedData, $key, $iv, $tag);

            case 'ChaCha20-Poly1305':
                return $this->decryptChaCha20Poly1305($encryptedData, $key, $iv);

            default:
                return $this->decryptAES256CBC($encryptedData, $key, $iv);
        }
    }

    /**
     * Decrypt using AES-256-CBC
     */
    private function decryptAES256CBC($encryptedData, $key, $iv)
    {
        $decrypted = openssl_decrypt($encryptedData, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \Exception('AES-256-CBC decryption failed');
        }

        return $decrypted;
    }

    /**
     * Decrypt using AES-256-GCM
     */
    private function decryptAES256GCM($encryptedData, $key, $iv, $tag)
    {
        $decrypted = openssl_decrypt($encryptedData, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($decrypted === false) {
            throw new \Exception('AES-256-GCM decryption failed');
        }

        return $decrypted;
    }

    /**
     * Decrypt using ChaCha20-Poly1305
     */
    private function decryptChaCha20Poly1305($encryptedData, $key, $iv)
    {
        if (!function_exists('sodium_crypto_aead_chacha20poly1305_decrypt')) {
            throw new \Exception('ChaCha20-Poly1305 not available');
        }

        $decrypted = sodium_crypto_aead_chacha20poly1305_decrypt($encryptedData, '', $iv, $key);

        if ($decrypted === false) {
            throw new \Exception('ChaCha20-Poly1305 decryption failed');
        }

        return $decrypted;
    }

    /**
     * Store encrypted content
     */
    private function storeEncryptedContent($encryptedContent, $courseId, $contentId)
    {
        try {
            $filename = "encrypted_{$contentId}_" . Str::random(8) . '.enc';
            $path = "courses/{$courseId}/encrypted";
            
            $filePath = Storage::disk('private')->putFileAs($path, $encryptedContent['data'], $filename);
            
            return $filePath ? $filePath : null;

        } catch (\Exception $e) {
            Log::error("Failed to store encrypted content: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Store encryption key
     */
    private function storeEncryptionKey($encryptionKey, $contentId)
    {
        try {
            // In production, use a proper key management service
            // For now, store in database (not recommended for production)
            $encryptionKey->content_id = $contentId;
            $encryptionKey->save();

        } catch (\Exception $e) {
            Log::error("Failed to store encryption key: " . $e->getMessage());
        }
    }

    /**
     * Get encryption key
     */
    private function getEncryptionKey($contentId)
    {
        return EncryptionKey::where('content_id', $contentId)->first();
    }

    /**
     * Read file content
     */
    private function readFileContent($filePath)
    {
        try {
            if (Storage::disk('public')->exists($filePath)) {
                return Storage::disk('public')->get($filePath);
            }

            if (Storage::disk('private')->exists($filePath)) {
                return Storage::disk('private')->get($filePath);
            }

            return null;

        } catch (\Exception $e) {
            Log::error("Failed to read file content: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if user can access content
     */
    private function canAccessContent($content, $userId)
    {
        // Check if user is enrolled in the course
        $enrollment = CourseEnrollment::where('user_id', $userId)
            ->where('course_id', $content->course_id)
            ->whereIn('status', ['enrolled', 'in_progress', 'completed'])
            ->first();

        if (!$enrollment) {
            return false;
        }

        // Check if content is accessible based on enrollment status
        if ($content->requires_completion && $enrollment->status !== 'completed') {
            return false;
        }

        return true;
    }

    /**
     * Log content access
     */
    private function logContentAccess($contentId, $userId, $action)
    {
        try {
            // Log access for audit purposes
            Log::info("Content access logged", [
                'content_id' => $contentId,
                'user_id' => $userId,
                'action' => $action,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::warning("Failed to log content access: " . $e->getMessage());
        }
    }

    /**
     * Rotate encryption keys
     */
    public function rotateEncryptionKeys($contentId = null)
    {
        try {
            $query = CourseContent::where('is_encrypted', true);

            if ($contentId) {
                $query->where('id', $contentId);
            }

            $encryptedContents = $query->get();
            $rotatedCount = 0;

            foreach ($encryptedContents as $content) {
                try {
                    // Decrypt with old key
                    $decryptedContent = $this->decryptContent($content->id);
                    if (!$decryptedContent['success']) {
                        continue;
                    }

                    // Generate new encryption key
                    $newKey = $this->generateEncryptionKey([
                        'algorithm' => $content->encryption_metadata['algorithm'],
                        'key_derivation' => $content->encryption_metadata['key_derivation']
                    ]);

                    // Re-encrypt with new key
                    $encryptedContent = $this->encryptData($decryptedContent['content'], $newKey, [
                        'algorithm' => $content->encryption_metadata['algorithm']
                    ]);

                    // Store new encrypted content
                    $newEncryptedFilePath = $this->storeEncryptedContent($encryptedContent, $content->course_id, $content->id);

                    // Update content record
                    $content->encrypted_file_path = $newEncryptedFilePath;
                    $content->encryption_metadata['key_id'] = $newKey->id;
                    $content->encryption_metadata['rotated_at'] = now()->toISOString();
                    $content->save();

                    // Mark old key as rotated
                    $oldKey = EncryptionKey::find($content->encryption_metadata['key_id']);
                    if ($oldKey) {
                        $oldKey->rotated_at = now();
                        $oldKey->save();
                    }

                    $rotatedCount++;

                } catch (\Exception $e) {
                    Log::warning("Failed to rotate key for content {$content->id}: " . $e->getMessage());
                }
            }

            Log::info("Key rotation completed", [
                'total_content' => $encryptedContents->count(),
                'rotated_count' => $rotatedCount
            ]);

            return [
                'success' => true,
                'rotated_count' => $rotatedCount,
                'message' => "Rotated encryption keys for {$rotatedCount} content items"
            ];

        } catch (\Exception $e) {
            Log::error("Failed to rotate encryption keys: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to rotate encryption keys'
            ];
        }
    }

    /**
     * Get encryption statistics
     */
    public function getEncryptionStats($courseId = null)
    {
        $query = CourseContent::where('is_encrypted', true);

        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        $stats = [
            'total_encrypted_content' => $query->count(),
            'encryption_by_algorithm' => $query->selectRaw("JSON_EXTRACT(encryption_metadata, '$.algorithm') as algorithm, COUNT(*) as count")
                ->groupBy('algorithm')
                ->pluck('count', 'algorithm'),
            'encryption_by_key_derivation' => $query->selectRaw("JSON_EXTRACT(encryption_metadata, '$.key_derivation') as key_derivation, COUNT(*) as count")
                ->groupBy('key_derivation')
                ->pluck('count', 'key_derivation'),
            'recently_encrypted' => $query->whereRaw("JSON_EXTRACT(encryption_metadata, '$.encrypted_at') >= ?", [now()->subDays(7)->toISOString()])->count(),
            'keys_expiring_soon' => EncryptionKey::where('expires_at', '<=', now()->addDays(30))->count()
        ];

        return $stats;
    }

    /**
     * Get encryption algorithms
     */
    public function getEncryptionAlgorithms()
    {
        return $this->encryptionAlgorithms;
    }

    /**
     * Get key derivation functions
     */
    public function getKeyDerivationFunctions()
    {
        return $this->keyDerivationFunctions;
    }

    /**
     * Validate encryption options
     */
    public function validateEncryptionOptions($options)
    {
        $errors = [];

        if (isset($options['algorithm']) && !in_array($options['algorithm'], array_keys($this->encryptionAlgorithms))) {
            $errors[] = 'Invalid encryption algorithm';
        }

        if (isset($options['key_derivation']) && !in_array($options['key_derivation'], array_keys($this->keyDerivationFunctions))) {
            $errors[] = 'Invalid key derivation function';
        }

        if (isset($options['iterations']) && (!is_numeric($options['iterations']) || $options['iterations'] < 1000)) {
            $errors[] = 'Iterations must be at least 1000';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
} 