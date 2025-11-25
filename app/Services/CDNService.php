<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CDNService
{
    protected $cloudflareApiToken;
    protected $cloudflareZoneId;
    protected $awsAccessKey;
    protected $awsSecretKey;
    protected $awsRegion;
    protected $awsBucket;
    protected $cdnProvider;
    
    public function __construct()
    {
        $this->cloudflareApiToken = config('services.cloudflare.api_token');
        $this->cloudflareZoneId = config('services.cloudflare.zone_id');
        $this->awsAccessKey = config('services.aws.access_key_id');
        $this->awsSecretKey = config('services.aws.secret_access_key');
        $this->awsRegion = config('services.aws.region');
        $this->awsBucket = config('services.aws.bucket');
        $this->cdnProvider = config('cdn.default_provider', 'cloudflare');
    }

    /**
     * Upload file to CDN
     */
    public function uploadFile($filePath, $destination, $options = [])
    {
        try {
            switch ($this->cdnProvider) {
                case 'cloudflare':
                    return $this->uploadToCloudflare($filePath, $destination, $options);
                case 'aws':
                    return $this->uploadToAWS($filePath, $destination, $options);
                default:
                    throw new \Exception("Unsupported CDN provider: {$this->cdnProvider}");
            }
        } catch (\Exception $e) {
            Log::error('CDN upload failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Upload to Cloudflare
     */
    private function uploadToCloudflare($filePath, $destination, $options = [])
    {
        if (!$this->cloudflareApiToken || !$this->cloudflareZoneId) {
            throw new \Exception('Cloudflare credentials not configured');
        }

        try {
            $fileContent = file_get_contents($filePath);
            $fileSize = filesize($filePath);
            $mimeType = mime_content_type($filePath);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->cloudflareApiToken,
                'Content-Type' => $mimeType
            ])->post("https://api.cloudflare.com/client/v4/zones/{$this->cloudflareZoneId}/assets", [
                'file' => base64_encode($fileContent),
                'metadata' => [
                    'filename' => basename($destination),
                    'size' => $fileSize,
                    'mime_type' => $mimeType
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                
                if ($result['success']) {
                    $asset = $result['result'];
                    
                    // Purge cache if requested
                    if ($options['purge_cache'] ?? false) {
                        $this->purgeCloudflareCache($destination);
                    }

                    return [
                        'provider' => 'cloudflare',
                        'url' => $asset['url'],
                        'asset_id' => $asset['id'],
                        'size' => $asset['size'],
                        'uploaded_at' => $asset['uploaded']
                    ];
                } else {
                    throw new \Exception('Cloudflare upload failed: ' . json_encode($result['errors']));
                }
            } else {
                throw new \Exception('Cloudflare API error: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('Cloudflare upload failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Upload to AWS S3
     */
    private function uploadToAWS($filePath, $destination, $options = [])
    {
        if (!$this->awsAccessKey || !$this->awsSecretKey || !$this->awsBucket) {
            throw new \Exception('AWS credentials not configured');
        }

        try {
            $s3 = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $this->awsRegion,
                'credentials' => [
                    'key' => $this->awsAccessKey,
                    'secret' => $this->awsSecretKey
                ]
            ]);

            $result = $s3->putObject([
                'Bucket' => $this->awsBucket,
                'Key' => $destination,
                'SourceFile' => $filePath,
                'ACL' => $options['acl'] ?? 'public-read',
                'CacheControl' => $options['cache_control'] ?? 'max-age=31536000',
                'ContentType' => mime_content_type($filePath),
                'Metadata' => $options['metadata'] ?? []
            ]);

            // Invalidate CloudFront cache if configured
            if (isset($options['invalidate_cache']) && $options['invalidate_cache']) {
                $this->invalidateCloudFrontCache($destination);
            }

            return [
                'provider' => 'aws',
                'url' => $result['ObjectURL'],
                'key' => $result['Key'],
                'etag' => $result['ETag'],
                'size' => filesize($filePath),
                'uploaded_at' => now()
            ];

        } catch (\Exception $e) {
            Log::error('AWS upload failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Purge Cloudflare cache
     */
    public function purgeCloudflareCache($urls)
    {
        if (!$this->cloudflareApiToken || !$this->cloudflareZoneId) {
            throw new \Exception('Cloudflare credentials not configured');
        }

        try {
            $urls = is_array($urls) ? $urls : [$urls];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->cloudflareApiToken,
                'Content-Type' => 'application/json'
            ])->post("https://api.cloudflare.com/client/v4/zones/{$this->cloudflareZoneId}/purge_cache", [
                'files' => $urls
            ]);

            if ($response->successful()) {
                $result = $response->json();
                
                if ($result['success']) {
                    Log::info('Cloudflare cache purged', ['urls' => $urls]);
                    return true;
                } else {
                    throw new \Exception('Cloudflare cache purge failed: ' . json_encode($result['errors']));
                }
            } else {
                throw new \Exception('Cloudflare API error: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('Cloudflare cache purge failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Invalidate CloudFront cache
     */
    public function invalidateCloudFrontCache($paths)
    {
        if (!$this->awsAccessKey || !$this->awsSecretKey) {
            throw new \Exception('AWS credentials not configured');
        }

        try {
            $cloudfront = new \Aws\CloudFront\CloudFrontClient([
                'version' => 'latest',
                'region' => $this->awsRegion,
                'credentials' => [
                    'key' => $this->awsAccessKey,
                    'secret' => $this->awsSecretKey
                ]
            ]);

            $distributionId = config('services.aws.cloudfront_distribution_id');
            if (!$distributionId) {
                throw new \Exception('CloudFront distribution ID not configured');
            }

            $paths = is_array($paths) ? $paths : [$paths];

            $result = $cloudfront->createInvalidation([
                'DistributionId' => $distributionId,
                'InvalidationBatch' => [
                    'Paths' => [
                        'Quantity' => count($paths),
                        'Items' => $paths
                    ],
                    'CallerReference' => 'lms-platform-' . time()
                ]
            ]);

            Log::info('CloudFront cache invalidated', [
                'distribution_id' => $distributionId,
                'paths' => $paths,
                'invalidation_id' => $result['Invalidation']['Id']
            ]);

            return $result['Invalidation']['Id'];

        } catch (\Exception $e) {
            Log::error('CloudFront cache invalidation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get CDN URL for file
     */
    public function getCDNUrl($filePath, $options = [])
    {
        $cdnDomain = config("cdn.{$this->cdnProvider}.domain");
        
        if (!$cdnDomain) {
            return $filePath; // Return original path if no CDN configured
        }

        $cdnUrl = rtrim($cdnDomain, '/') . '/' . ltrim($filePath, '/');

        // Add query parameters if specified
        if (isset($options['query_params'])) {
            $cdnUrl .= '?' . http_build_query($options['query_params']);
        }

        return $cdnUrl;
    }

    /**
     * Optimize image for CDN
     */
    public function optimizeImage($filePath, $options = [])
    {
        try {
            $width = $options['width'] ?? null;
            $height = $options['height'] ?? null;
            $quality = $options['quality'] ?? 80;
            $format = $options['format'] ?? 'webp';

            $image = \Intervention\Image\Facades\Image::make($filePath);

            // Resize if dimensions specified
            if ($width || $height) {
                $image->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }

            // Convert format if specified
            if ($format !== 'original') {
                $image->encode($format, $quality);
            }

            // Generate optimized file path
            $optimizedPath = $this->getOptimizedImagePath($filePath, $options);
            
            // Save optimized image
            $image->save($optimizedPath, $quality);

            return $optimizedPath;

        } catch (\Exception $e) {
            Log::error('Image optimization failed: ' . $e->getMessage());
            return $filePath; // Return original if optimization fails
        }
    }

    /**
     * Get optimized image path
     */
    private function getOptimizedImagePath($originalPath, $options)
    {
        $pathInfo = pathinfo($originalPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        $suffix = '';
        if (isset($options['width']) || isset($options['height'])) {
            $suffix .= '_' . ($options['width'] ?? 'auto') . 'x' . ($options['height'] ?? 'auto');
        }
        if (isset($options['quality'])) {
            $suffix .= '_q' . $options['quality'];
        }
        if (isset($options['format']) && $options['format'] !== 'original') {
            $suffix .= '.' . $options['format'];
        } else {
            $suffix .= '.' . $pathInfo['extension'];
        }
        
        return "{$directory}/{$filename}{$suffix}";
    }

    /**
     * Generate responsive images
     */
    public function generateResponsiveImages($filePath, $breakpoints = [])
    {
        if (empty($breakpoints)) {
            $breakpoints = [
                'xs' => 480,
                'sm' => 768,
                'md' => 1024,
                'lg' => 1200,
                'xl' => 1920
            ];
        }

        $responsiveImages = [];

        foreach ($breakpoints as $breakpoint => $width) {
            try {
                $optimizedPath = $this->optimizeImage($filePath, [
                    'width' => $width,
                    'quality' => 80,
                    'format' => 'webp'
                ]);

                $responsiveImages[$breakpoint] = [
                    'path' => $optimizedPath,
                    'width' => $width,
                    'url' => $this->getCDNUrl($optimizedPath)
                ];

            } catch (\Exception $e) {
                Log::warning("Failed to generate responsive image for breakpoint {$breakpoint}: " . $e->getMessage());
            }
        }

        return $responsiveImages;
    }

    /**
     * Upload course content to CDN
     */
    public function uploadCourseContent($courseId, $contentType, $options = [])
    {
        try {
            $course = \App\Models\Course::findOrFail($courseId);
            
            $uploadResults = [];
            
            switch ($contentType) {
                case 'videos':
                    $uploadResults = $this->uploadCourseVideos($course, $options);
                    break;
                case 'documents':
                    $uploadResults = $this->uploadCourseDocuments($course, $options);
                    break;
                case 'images':
                    $uploadResults = $this->uploadCourseImages($course, $options);
                    break;
                case 'all':
                    $uploadResults = array_merge(
                        $this->uploadCourseVideos($course, $options),
                        $this->uploadCourseDocuments($course, $options),
                        $this->uploadCourseImages($course, $options)
                    );
                    break;
                default:
                    throw new \Exception("Unsupported content type: {$contentType}");
            }

            // Update course CDN status
            $course->update([
                'cdn_enabled' => true,
                'cdn_updated_at' => now()
            ]);

            Log::info("Course content uploaded to CDN", [
                'course_id' => $courseId,
                'content_type' => $contentType,
                'upload_count' => count($uploadResults)
            ]);

            return $uploadResults;

        } catch (\Exception $e) {
            Log::error('Course content CDN upload failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Upload course videos to CDN
     */
    private function uploadCourseVideos($course, $options)
    {
        $uploadResults = [];
        
        $videos = $course->lessons()
            ->where('type', 'video')
            ->whereNotNull('video_path')
            ->get();

        foreach ($videos as $lesson) {
            try {
                $videoPath = storage_path('app/' . $lesson->video_path);
                
                if (file_exists($videoPath)) {
                    $destination = "courses/{$course->id}/videos/" . basename($lesson->video_path);
                    
                    $result = $this->uploadFile($videoPath, $destination, $options);
                    
                    // Update lesson with CDN URL
                    $lesson->update([
                        'cdn_url' => $result['url'],
                        'cdn_provider' => $result['provider']
                    ]);

                    $uploadResults[] = [
                        'lesson_id' => $lesson->id,
                        'type' => 'video',
                        'result' => $result
                    ];
                }
            } catch (\Exception $e) {
                Log::error("Failed to upload video for lesson {$lesson->id}: " . $e->getMessage());
            }
        }

        return $uploadResults;
    }

    /**
     * Upload course documents to CDN
     */
    private function uploadCourseDocuments($course, $options)
    {
        $uploadResults = [];
        
        $documents = $course->lessons()
            ->whereIn('type', ['document', 'pdf', 'file'])
            ->whereNotNull('file_path')
            ->get();

        foreach ($documents as $lesson) {
            try {
                $filePath = storage_path('app/' . $lesson->file_path);
                
                if (file_exists($filePath)) {
                    $destination = "courses/{$course->id}/documents/" . basename($lesson->file_path);
                    
                    $result = $this->uploadFile($filePath, $destination, $options);
                    
                    // Update lesson with CDN URL
                    $lesson->update([
                        'cdn_url' => $result['url'],
                        'cdn_provider' => $result['provider']
                    ]);

                    $uploadResults[] = [
                        'lesson_id' => $lesson->id,
                        'type' => 'document',
                        'result' => $result
                    ];
                }
            } catch (\Exception $e) {
                Log::error("Failed to upload document for lesson {$lesson->id}: " . $e->getMessage());
            }
        }

        return $uploadResults;
    }

    /**
     * Upload course images to CDN
     */
    private function uploadCourseImages($course, $options)
    {
        $uploadResults = [];
        
        // Course thumbnail
        if ($course->thumbnail) {
            try {
                $thumbnailPath = storage_path('app/' . $course->thumbnail);
                
                if (file_exists($thumbnailPath)) {
                    $destination = "courses/{$course->id}/images/thumbnail." . pathinfo($course->thumbnail, PATHINFO_EXTENSION);
                    
                    $result = $this->uploadFile($thumbnailPath, $destination, $options);
                    
                    // Update course with CDN URL
                    $course->update(['cdn_thumbnail_url' => $result['url']]);

                    $uploadResults[] = [
                        'type' => 'thumbnail',
                        'result' => $result
                    ];
                }
            } catch (\Exception $e) {
                Log::error("Failed to upload course thumbnail: " . $e->getMessage());
            }
        }

        // Lesson images
        $imageLessons = $course->lessons()
            ->where('type', 'image')
            ->whereNotNull('image_path')
            ->get();

        foreach ($imageLessons as $lesson) {
            try {
                $imagePath = storage_path('app/' . $lesson->image_path);
                
                if (file_exists($imagePath)) {
                    $destination = "courses/{$course->id}/images/" . basename($lesson->image_path);
                    
                    $result = $this->uploadFile($imagePath, $destination, $options);
                    
                    // Update lesson with CDN URL
                    $lesson->update([
                        'cdn_url' => $result['url'],
                        'cdn_provider' => $result['provider']
                    ]);

                    $uploadResults[] = [
                        'lesson_id' => $lesson->id,
                        'type' => 'image',
                        'result' => $result
                    ];
                }
            } catch (\Exception $e) {
                Log::error("Failed to upload image for lesson {$lesson->id}: " . $e->getMessage());
            }
        }

        return $uploadResults;
    }

    /**
     * Get CDN statistics
     */
    public function getCDNStats($period = '24h')
    {
        $startTime = match($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay()
        };

        // This would query CDN usage logs
        // For now, return basic stats
        return [
            'provider' => $this->cdnProvider,
            'total_files' => 0,
            'total_size' => 0,
            'bandwidth_used' => 0,
            'cache_hit_rate' => 0,
            'period' => $period,
            'start_time' => $startTime,
            'end_time' => now()
        ];
    }

    /**
     * Check CDN health
     */
    public function checkCDNHealth()
    {
        try {
            switch ($this->cdnProvider) {
                case 'cloudflare':
                    return $this->checkCloudflareHealth();
                case 'aws':
                    return $this->checkAWSHealth();
                default:
                    return ['status' => 'unknown', 'message' => 'Unknown CDN provider'];
            }
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check Cloudflare health
     */
    private function checkCloudflareHealth()
    {
        if (!$this->cloudflareApiToken || !$this->cloudflareZoneId) {
            return ['status' => 'not_configured', 'message' => 'Cloudflare credentials not configured'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->cloudflareApiToken
            ])->get("https://api.cloudflare.com/client/v4/zones/{$this->cloudflareZoneId}");

            if ($response->successful()) {
                return ['status' => 'healthy', 'message' => 'Cloudflare API responding normally'];
            } else {
                return ['status' => 'error', 'message' => 'Cloudflare API error: ' . $response->status()];
            }
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Cloudflare connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Check AWS health
     */
    private function checkAWSHealth()
    {
        if (!$this->awsAccessKey || !$this->awsSecretKey) {
            return ['status' => 'not_configured', 'message' => 'AWS credentials not configured'];
        }

        try {
            $s3 = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $this->awsRegion,
                'credentials' => [
                    'key' => $this->awsAccessKey,
                    'secret' => $this->awsSecretKey
                ]
            ]);

            $result = $s3->headBucket(['Bucket' => $this->awsBucket]);

            return ['status' => 'healthy', 'message' => 'AWS S3 bucket accessible'];

        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'AWS connection failed: ' . $e->getMessage()];
        }
    }
} 