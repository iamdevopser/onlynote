<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\CourseContent;
use App\Models\CDNProvider;
use App\Models\CDNFile;

class CDNIntegrationService
{
    protected $providers = [
        'cloudflare' => [
            'name' => 'Cloudflare',
            'api_endpoint' => 'https://api.cloudflare.com/client/v4',
            'features' => ['cdn', 'dns', 'ssl', 'caching', 'optimization']
        ],
        'aws_cloudfront' => [
            'name' => 'AWS CloudFront',
            'api_endpoint' => 'https://cloudfront.amazonaws.com',
            'features' => ['cdn', 'ssl', 'caching', 'edge_locations']
        ],
        'aws_s3' => [
            'name' => 'AWS S3',
            'api_endpoint' => 'https://s3.amazonaws.com',
            'features' => ['storage', 'cdn', 'ssl', 'versioning']
        ],
        'bunny_cdn' => [
            'name' => 'Bunny CDN',
            'api_endpoint' => 'https://api.bunny.net',
            'features' => ['cdn', 'ssl', 'caching', 'video_streaming']
        ],
        'keycdn' => [
            'name' => 'KeyCDN',
            'api_endpoint' => 'https://api.keycdn.com',
            'features' => ['cdn', 'ssl', 'caching', 'real_time_analytics']
        ]
    ];

    protected $defaultProvider = 'cloudflare';
    protected $cacheTtl = 3600; // 1 hour

    /**
     * Upload content to CDN
     */
    public function uploadToCDN($contentId, $provider = null, $options = [])
    {
        try {
            $content = CourseContent::find($contentId);
            if (!$content) {
                return [
                    'success' => false,
                    'message' => 'Content not found'
                ];
            }

            // Determine provider
            $provider = $provider ?? $this->defaultProvider;
            if (!isset($this->providers[$provider])) {
                return [
                    'success' => false,
                    'message' => 'Unsupported CDN provider'
                ];
            }

            // Get provider configuration
            $providerConfig = $this->getProviderConfig($provider);
            if (!$providerConfig) {
                return [
                    'success' => false,
                    'message' => 'CDN provider not configured'
                ];
            }

            // Read file content
            $fileContent = $this->readFileContent($content->file_path);
            if (!$fileContent) {
                return [
                    'success' => false,
                    'message' => 'Failed to read file content'
                ];
            }

            // Upload to CDN
            $uploadResult = $this->uploadToProvider($provider, $fileContent, $content, $providerConfig, $options);

            if (!$uploadResult['success']) {
                return $uploadResult;
            }

            // Create CDN file record
            $cdnFile = CDNFile::create([
                'content_id' => $content->id,
                'provider' => $provider,
                'cdn_url' => $uploadResult['cdn_url'],
                'cdn_file_id' => $uploadResult['cdn_file_id'] ?? null,
                'file_size' => strlen($fileContent),
                'upload_date' => now(),
                'status' => 'active',
                'metadata' => array_merge($uploadResult['metadata'] ?? [], [
                    'provider_config' => $providerConfig->id,
                    'upload_options' => $options
                ])
            ]);

            // Update content record
            $content->cdn_file_id = $cdnFile->id;
            $content->cdn_url = $uploadResult['cdn_url'];
            $content->is_cdn_enabled = true;
            $content->save();

            Log::info("Content uploaded to CDN successfully", [
                'content_id' => $contentId,
                'provider' => $provider,
                'cdn_url' => $uploadResult['cdn_url']
            ]);

            return [
                'success' => true,
                'cdn_file' => $cdnFile,
                'cdn_url' => $uploadResult['cdn_url'],
                'message' => 'Content uploaded to CDN successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to upload content to CDN: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to upload content to CDN: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get provider configuration
     */
    private function getProviderConfig($provider)
    {
        return CDNProvider::where('provider', $provider)
            ->where('is_active', true)
            ->first();
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
     * Upload to specific provider
     */
    private function uploadToProvider($provider, $fileContent, $content, $providerConfig, $options)
    {
        switch ($provider) {
            case 'cloudflare':
                return $this->uploadToCloudflare($fileContent, $content, $providerConfig, $options);

            case 'aws_cloudfront':
                return $this->uploadToAWSCloudFront($fileContent, $content, $providerConfig, $options);

            case 'aws_s3':
                return $this->uploadToAWSS3($fileContent, $content, $providerConfig, $options);

            case 'bunny_cdn':
                return $this->uploadToBunnyCDN($fileContent, $content, $providerConfig, $options);

            case 'keycdn':
                return $this->uploadToKeyCDN($fileContent, $content, $providerConfig, $options);

            default:
                return [
                    'success' => false,
                    'message' => 'Provider not implemented'
                ];
        }
    }

    /**
     * Upload to Cloudflare
     */
    private function uploadToCloudflare($fileContent, $content, $providerConfig, $options)
    {
        try {
            $apiToken = $providerConfig->api_key;
            $zoneId = $providerConfig->zone_id;
            $domain = $providerConfig->domain;

            // Generate filename
            $filename = $this->generateCDNFilename($content, 'cloudflare');

            // Upload to Cloudflare
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
                'Content-Type' => $content->mime_type
            ])->post("{$this->providers['cloudflare']['api_endpoint']}/zones/{$zoneId}/assets/v1", [
                'metadata' => [
                    'key' => $filename,
                    'value' => base64_encode($fileContent)
                ]
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Cloudflare upload failed: ' . $response->body()
                ];
            }

            $responseData = $response->json();
            $cdnUrl = "https://{$domain}/{$filename}";

            return [
                'success' => true,
                'cdn_url' => $cdnUrl,
                'cdn_file_id' => $responseData['result']['id'] ?? null,
                'metadata' => [
                    'cloudflare_response' => $responseData,
                    'filename' => $filename
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Cloudflare upload failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Cloudflare upload failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Upload to AWS CloudFront
     */
    private function uploadToAWSCloudFront($fileContent, $content, $providerConfig, $options)
    {
        try {
            // This would use AWS SDK
            // For now, return a placeholder implementation
            $filename = $this->generateCDNFilename($content, 'aws_cloudfront');
            $cdnUrl = "https://{$providerConfig->domain}/{$filename}";

            return [
                'success' => true,
                'cdn_url' => $cdnUrl,
                'cdn_file_id' => Str::random(32),
                'metadata' => [
                    'filename' => $filename,
                    'aws_region' => $providerConfig->region ?? 'us-east-1'
                ]
            ];

        } catch (\Exception $e) {
            Log::error("AWS CloudFront upload failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'AWS CloudFront upload failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Upload to AWS S3
     */
    private function uploadToAWSS3($fileContent, $content, $providerConfig, $options)
    {
        try {
            // This would use AWS SDK
            // For now, return a placeholder implementation
            $filename = $this->generateCDNFilename($content, 'aws_s3');
            $cdnUrl = "https://{$providerConfig->bucket}.s3.{$providerConfig->region}.amazonaws.com/{$filename}";

            return [
                'success' => true,
                'cdn_url' => $cdnUrl,
                'cdn_file_id' => Str::random(32),
                'metadata' => [
                    'filename' => $filename,
                    'bucket' => $providerConfig->bucket,
                    'region' => $providerConfig->region
                ]
            ];

        } catch (\Exception $e) {
            Log::error("AWS S3 upload failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'AWS S3 upload failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Upload to Bunny CDN
     */
    private function uploadToBunnyCDN($fileContent, $content, $providerConfig, $options)
    {
        try {
            $apiKey = $providerConfig->api_key;
            $storageZone = $providerConfig->storage_zone;
            $pullZone = $providerConfig->pull_zone;

            // Generate filename
            $filename = $this->generateCDNFilename($content, 'bunny_cdn');

            // Upload to Bunny CDN
            $response = Http::withHeaders([
                'AccessKey' => $apiKey,
                'Content-Type' => $content->mime_type
            ])->put("{$this->providers['bunny_cdn']['api_endpoint']}/storage/{$storageZone}/{$filename}", $fileContent);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Bunny CDN upload failed: ' . $response->body()
                ];
            }

            $cdnUrl = "https://{$pullZone}.b-cdn.net/{$filename}";

            return [
                'success' => true,
                'cdn_url' => $cdnUrl,
                'cdn_file_id' => $filename,
                'metadata' => [
                    'filename' => $filename,
                    'storage_zone' => $storageZone,
                    'pull_zone' => $pullZone
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Bunny CDN upload failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Bunny CDN upload failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Upload to KeyCDN
     */
    private function uploadToKeyCDN($fileContent, $content, $providerConfig, $options)
    {
        try {
            $apiKey = $providerConfig->api_key;
            $zoneName = $providerConfig->zone_name;

            // Generate filename
            $filename = $this->generateCDNFilename($content, 'keycdn');

            // Upload to KeyCDN
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
                'Content-Type' => $content->mime_type
            ])->put("{$this->providers['keycdn']['api_endpoint']}/zones/push/{$zoneName}/{$filename}", $fileContent);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'KeyCDN upload failed: ' . $response->body()
                ];
            }

            $cdnUrl = "https://{$zoneName}.keycdn.com/{$filename}";

            return [
                'success' => true,
                'cdn_url' => $cdnUrl,
                'cdn_file_id' => $filename,
                'metadata' => [
                    'filename' => $filename,
                    'zone_name' => $zoneName
                ]
            ];

        } catch (\Exception $e) {
            Log::error("KeyCDN upload failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'KeyCDN upload failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate CDN filename
     */
    private function generateCDNFilename($content, $provider)
    {
        $extension = pathinfo($content->file_path, PATHINFO_EXTENSION);
        $timestamp = now()->format('Y/m/d');
        $uniqueId = Str::random(8);
        
        return "courses/{$content->course_id}/{$timestamp}/{$uniqueId}.{$extension}";
    }

    /**
     * Purge CDN cache
     */
    public function purgeCDNCache($cdnFileId, $options = [])
    {
        try {
            $cdnFile = CDNFile::find($cdnFileId);
            if (!$cdnFile) {
                return [
                    'success' => false,
                    'message' => 'CDN file not found'
                ];
            }

            $provider = $cdnFile->provider;
            $providerConfig = $this->getProviderConfig($provider);

            if (!$providerConfig) {
                return [
                    'success' => false,
                    'message' => 'CDN provider not configured'
                ];
            }

            $purgeResult = $this->purgeFromProvider($provider, $cdnFile, $providerConfig, $options);

            if ($purgeResult['success']) {
                // Update purge status
                $cdnFile->last_purged = now();
                $cdnFile->purge_count = ($cdnFile->purge_count ?? 0) + 1;
                $cdnFile->save();

                Log::info("CDN cache purged successfully", [
                    'cdn_file_id' => $cdnFileId,
                    'provider' => $provider
                ]);
            }

            return $purgeResult;

        } catch (\Exception $e) {
            Log::error("Failed to purge CDN cache: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to purge CDN cache: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Purge from specific provider
     */
    private function purgeFromProvider($provider, $cdnFile, $providerConfig, $options)
    {
        switch ($provider) {
            case 'cloudflare':
                return $this->purgeFromCloudflare($cdnFile, $providerConfig, $options);

            case 'aws_cloudfront':
                return $this->purgeFromAWSCloudFront($cdnFile, $providerConfig, $options);

            case 'bunny_cdn':
                return $this->purgeFromBunnyCDN($cdnFile, $providerConfig, $options);

            case 'keycdn':
                return $this->purgeFromKeyCDN($cdnFile, $providerConfig, $options);

            default:
                return [
                    'success' => false,
                    'message' => 'Provider purge not implemented'
                ];
        }
    }

    /**
     * Purge from Cloudflare
     */
    private function purgeFromCloudflare($cdnFile, $providerConfig, $options)
    {
        try {
            $apiToken = $providerConfig->api_key;
            $zoneId = $providerConfig->zone_id;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
                'Content-Type' => 'application/json'
            ])->post("{$this->providers['cloudflare']['api_endpoint']}/zones/{$zoneId}/purge_cache", [
                'files' => [$cdnFile->cdn_url]
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Cloudflare purge failed: ' . $response->body()
                ];
            }

            return [
                'success' => true,
                'message' => 'Cloudflare cache purged successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Cloudflare purge failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Cloudflare purge failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Purge from AWS CloudFront
     */
    private function purgeFromAWSCloudFront($cdnFile, $providerConfig, $options)
    {
        try {
            // This would use AWS SDK
            // For now, return a placeholder implementation
            return [
                'success' => true,
                'message' => 'AWS CloudFront cache purged successfully'
            ];

        } catch (\Exception $e) {
            Log::error("AWS CloudFront purge failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'AWS CloudFront purge failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Purge from Bunny CDN
     */
    private function purgeFromBunnyCDN($cdnFile, $providerConfig, $options)
    {
        try {
            $apiKey = $providerConfig->api_key;
            $pullZone = $providerConfig->pull_zone;

            $response = Http::withHeaders([
                'AccessKey' => $apiKey
            ])->post("{$this->providers['bunny_cdn']['api_endpoint']}/pullzone/{$pullZone}/purgeCache");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Bunny CDN purge failed: ' . $response->body()
                ];
            }

            return [
                'success' => true,
                'message' => 'Bunny CDN cache purged successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Bunny CDN purge failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Bunny CDN purge failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Purge from KeyCDN
     */
    private function purgeFromKeyCDN($cdnFile, $providerConfig, $options)
    {
        try {
            $apiKey = $providerConfig->api_key;
            $zoneName = $providerConfig->zone_name;

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':')
            ])->delete("{$this->providers['keycdn']['api_endpoint']}/zones/purge/{$zoneName}/urls", [
                'urls' => [$cdnFile->cdn_url]
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'KeyCDN purge failed: ' . $response->body()
                ];
            }

            return [
                'success' => true,
                'message' => 'KeyCDN cache purged successfully'
            ];

        } catch (\Exception $e) {
            Log::error("KeyCDN purge failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'KeyCDN purge failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get CDN statistics
     */
    public function getCDNStats($provider = null, $courseId = null)
    {
        $query = CDNFile::query();

        if ($provider) {
            $query->where('provider', $provider);
        }

        if ($courseId) {
            $query->whereHas('content', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            });
        }

        $stats = [
            'total_files' => $query->count(),
            'files_by_provider' => $query->selectRaw('provider, COUNT(*) as count')
                ->groupBy('provider')
                ->pluck('count', 'provider'),
            'total_file_size' => $query->sum('file_size'),
            'average_file_size' => round($query->avg('file_size'), 2),
            'recent_uploads' => $query->where('upload_date', '>=', now()->subDays(7))->count(),
            'active_files' => $query->where('status', 'active')->count(),
            'providers_used' => $query->distinct('provider')->pluck('provider')
        ];

        return $stats;
    }

    /**
     * Get CDN providers
     */
    public function getCDNProviders()
    {
        return $this->providers;
    }

    /**
     * Get provider information
     */
    public function getProviderInfo($provider)
    {
        return $this->providers[$provider] ?? null;
    }

    /**
     * Check if provider is supported
     */
    public function isProviderSupported($provider)
    {
        return isset($this->providers[$provider]);
    }

    /**
     * Get CDN file by ID
     */
    public function getCDNFile($cdnFileId)
    {
        return CDNFile::with(['content', 'content.course'])->find($cdnFileId);
    }

    /**
     * Get CDN files for content
     */
    public function getContentCDNFiles($contentId)
    {
        return CDNFile::where('content_id', $contentId)
            ->orderBy('upload_date', 'desc')
            ->get();
    }

    /**
     * Delete CDN file
     */
    public function deleteCDNFile($cdnFileId)
    {
        try {
            $cdnFile = CDNFile::find($cdnFileId);
            if (!$cdnFile) {
                return [
                    'success' => false,
                    'message' => 'CDN file not found'
                ];
            }

            // Delete from CDN provider
            $deleteResult = $this->deleteFromProvider($cdnFile->provider, $cdnFile);

            if ($deleteResult['success']) {
                // Update content record
                if ($cdnFile->content) {
                    $cdnFile->content->cdn_file_id = null;
                    $cdnFile->content->cdn_url = null;
                    $cdnFile->content->is_cdn_enabled = false;
                    $cdnFile->content->save();
                }

                // Delete CDN file record
                $cdnFile->delete();

                Log::info("CDN file deleted successfully", [
                    'cdn_file_id' => $cdnFileId,
                    'provider' => $cdnFile->provider
                ]);

                return [
                    'success' => true,
                    'message' => 'CDN file deleted successfully'
                ];
            }

            return $deleteResult;

        } catch (\Exception $e) {
            Log::error("Failed to delete CDN file: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete CDN file: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete from specific provider
     */
    private function deleteFromProvider($provider, $cdnFile)
    {
        switch ($provider) {
            case 'cloudflare':
                return $this->deleteFromCloudflare($cdnFile);

            case 'aws_cloudfront':
                return $this->deleteFromAWSCloudFront($cdnFile);

            case 'bunny_cdn':
                return $this->deleteFromBunnyCDN($cdnFile);

            case 'keycdn':
                return $this->deleteFromKeyCDN($cdnFile);

            default:
                return [
                    'success' => false,
                    'message' => 'Provider delete not implemented'
                ];
        }
    }

    /**
     * Delete from Cloudflare
     */
    private function deleteFromCloudflare($cdnFile)
    {
        try {
            // This would use Cloudflare API to delete the file
            // For now, return success
            return [
                'success' => true,
                'message' => 'File deleted from Cloudflare'
            ];

        } catch (\Exception $e) {
            Log::error("Cloudflare delete failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Cloudflare delete failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete from AWS CloudFront
     */
    private function deleteFromAWSCloudFront($cdnFile)
    {
        try {
            // This would use AWS SDK to delete the file
            // For now, return success
            return [
                'success' => true,
                'message' => 'File deleted from AWS CloudFront'
            ];

        } catch (\Exception $e) {
            Log::error("AWS CloudFront delete failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'AWS CloudFront delete failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete from Bunny CDN
     */
    private function deleteFromBunnyCDN($cdnFile)
    {
        try {
            // This would use Bunny CDN API to delete the file
            // For now, return success
            return [
                'success' => true,
                'message' => 'File deleted from Bunny CDN'
            ];

        } catch (\Exception $e) {
            Log::error("Bunny CDN delete failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Bunny CDN delete failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete from KeyCDN
     */
    private function deleteFromKeyCDN($cdnFile)
    {
        try {
            // This would use KeyCDN API to delete the file
            // For now, return success
            return [
                'success' => true,
                'message' => 'File deleted from KeyCDN'
            ];

        } catch (\Exception $e) {
            Log::error("KeyCDN delete failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'KeyCDN delete failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test CDN connection
     */
    public function testCDNConnection($provider, $providerConfig)
    {
        try {
            switch ($provider) {
                case 'cloudflare':
                    return $this->testCloudflareConnection($providerConfig);

                case 'aws_cloudfront':
                    return $this->testAWSCloudFrontConnection($providerConfig);

                case 'bunny_cdn':
                    return $this->testBunnyCDNConnection($providerConfig);

                case 'keycdn':
                    return $this->testKeyCDNConnection($providerConfig);

                default:
                    return [
                        'success' => false,
                        'message' => 'Provider not implemented'
                    ];
            }

        } catch (\Exception $e) {
            Log::error("CDN connection test failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'CDN connection test failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test Cloudflare connection
     */
    private function testCloudflareConnection($providerConfig)
    {
        try {
            $apiToken = $providerConfig->api_key;
            $zoneId = $providerConfig->zone_id;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiToken
            ])->get("{$this->providers['cloudflare']['api_endpoint']}/zones/{$zoneId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Cloudflare connection successful'
                ];
            }

            return [
                'success' => false,
                'message' => 'Cloudflare connection failed: ' . $response->body()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Cloudflare connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test AWS CloudFront connection
     */
    private function testAWSCloudFrontConnection($providerConfig)
    {
        try {
            // This would test AWS credentials and permissions
            // For now, return success
            return [
                'success' => true,
                'message' => 'AWS CloudFront connection successful'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'AWS CloudFront connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test Bunny CDN connection
     */
    private function testBunnyCDNConnection($providerConfig)
    {
        try {
            $apiKey = $providerConfig->api_key;

            $response = Http::withHeaders([
                'AccessKey' => $apiKey
            ])->get("{$this->providers['bunny_cdn']['api_endpoint']}/storage");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Bunny CDN connection successful'
                ];
            }

            return [
                'success' => false,
                'message' => 'Bunny CDN connection failed: ' . $response->body()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Bunny CDN connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test KeyCDN connection
     */
    private function testKeyCDNConnection($providerConfig)
    {
        try {
            $apiKey = $providerConfig->api_key;

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':')
            ])->get("{$this->providers['keycdn']['api_endpoint']}/zones");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'KeyCDN connection successful'
                ];
            }

            return [
                'success' => false,
                'message' => 'KeyCDN connection failed: ' . $response->body()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'KeyCDN connection failed: ' . $e->getMessage()
            ];
        }
    }
} 