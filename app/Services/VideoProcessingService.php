<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use App\Models\Video;

class VideoProcessingService
{
    protected $ffmpegPath;
    protected $ffprobePath;
    protected $outputFormats = ['mp4', 'webm', 'ogg'];
    protected $qualityPresets = ['low', 'medium', 'high', 'ultra'];
    
    public function __construct()
    {
        $this->ffmpegPath = config('video.ffmpeg_path', '/usr/bin/ffmpeg');
        $this->ffprobePath = config('video.ffprobe_path', '/usr/bin/ffprobe');
    }

    /**
     * Process uploaded video
     */
    public function processVideo($videoPath, $options = [])
    {
        try {
            $videoInfo = $this->getVideoInfo($videoPath);
            
            if (!$videoInfo) {
                throw new \Exception('Video bilgisi alınamadı');
            }
            
            $processedVideos = [];
            
            // Generate different quality versions
            foreach ($this->qualityPresets as $quality) {
                $processedVideo = $this->generateQualityVersion($videoPath, $quality, $options);
                if ($processedVideo) {
                    $processedVideos[$quality] = $processedVideo;
                }
            }
            
            // Generate thumbnail
            $thumbnail = $this->generateThumbnail($videoPath, $options);
            
            // Generate preview video
            $preview = $this->generatePreview($videoPath, $options);
            
            return [
                'original_info' => $videoInfo,
                'processed_versions' => $processedVideos,
                'thumbnail' => $thumbnail,
                'preview' => $preview
            ];
            
        } catch (\Exception $e) {
            Log::error('Video processing failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get video information
     */
    public function getVideoInfo($videoPath)
    {
        try {
            $command = "{$this->ffprobePath} -v quiet -print_format json -show_format -show_streams \"{$videoPath}\"";
            
            $result = Process::run($command);
            
            if ($result->successful()) {
                $info = json_decode($result->output(), true);
                
                return [
                    'duration' => $info['format']['duration'] ?? 0,
                    'size' => $info['format']['size'] ?? 0,
                    'bitrate' => $info['format']['bit_rate'] ?? 0,
                    'format' => $info['format']['format_name'] ?? '',
                    'streams' => $this->extractStreamInfo($info['streams'] ?? [])
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get video info: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract stream information
     */
    private function extractStreamInfo($streams)
    {
        $info = [
            'video' => [],
            'audio' => []
        ];
        
        foreach ($streams as $stream) {
            if ($stream['codec_type'] === 'video') {
                $info['video'][] = [
                    'codec' => $stream['codec_name'] ?? '',
                    'width' => $stream['width'] ?? 0,
                    'height' => $stream['height'] ?? 0,
                    'fps' => $this->extractFPS($stream['r_frame_rate'] ?? ''),
                    'bitrate' => $stream['bit_rate'] ?? 0
                ];
            } elseif ($stream['codec_type'] === 'audio') {
                $info['audio'][] = [
                    'codec' => $stream['codec_name'] ?? '',
                    'channels' => $stream['channels'] ?? 0,
                    'sample_rate' => $stream['sample_rate'] ?? 0,
                    'bitrate' => $stream['bit_rate'] ?? 0
                ];
            }
        }
        
        return $info;
    }

    /**
     * Extract FPS from frame rate string
     */
    private function extractFPS($frameRate)
    {
        if (strpos($frameRate, '/') !== false) {
            list($num, $den) = explode('/', $frameRate);
            return $den > 0 ? round($num / $den, 2) : 0;
        }
        
        return floatval($frameRate);
    }

    /**
     * Generate quality version
     */
    private function generateQualityVersion($inputPath, $quality, $options = [])
    {
        $outputPath = $this->getOutputPath($inputPath, $quality);
        $settings = $this->getQualitySettings($quality);
        
        $command = $this->buildFFmpegCommand($inputPath, $outputPath, $settings, $options);
        
        try {
            $result = Process::run($command);
            
            if ($result->successful() && file_exists($outputPath)) {
                return [
                    'path' => $outputPath,
                    'size' => filesize($outputPath),
                    'quality' => $quality,
                    'settings' => $settings
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error("Failed to generate {$quality} quality version: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get quality settings
     */
    private function getQualitySettings($quality)
    {
        return match($quality) {
            'low' => [
                'video_bitrate' => '500k',
                'audio_bitrate' => '64k',
                'resolution' => '640x360',
                'fps' => 24
            ],
            'medium' => [
                'video_bitrate' => '1000k',
                'audio_bitrate' => '128k',
                'resolution' => '1280x720',
                'fps' => 30
            ],
            'high' => [
                'video_bitrate' => '2000k',
                'audio_bitrate' => '192k',
                'resolution' => '1920x1080',
                'fps' => 30
            ],
            'ultra' => [
                'video_bitrate' => '4000k',
                'audio_bitrate' => '256k',
                'resolution' => '2560x1440',
                'fps' => 60
            ],
            default => [
                'video_bitrate' => '1000k',
                'audio_bitrate' => '128k',
                'resolution' => '1280x720',
                'fps' => 30
            ]
        };
    }

    /**
     * Build FFmpeg command
     */
    private function buildFFmpegCommand($inputPath, $outputPath, $settings, $options = [])
    {
        $command = "{$this->ffmpegPath} -i \"{$inputPath}\"";
        
        // Video settings
        $command .= " -c:v libx264 -preset medium";
        $command .= " -b:v {$settings['video_bitrate']}";
        $command .= " -vf scale={$settings['resolution']}:force_original_aspect_ratio=decrease";
        $command .= " -r {$settings['fps']}";
        
        // Audio settings
        $command .= " -c:a aac -b:a {$settings['audio_bitrate']}";
        
        // Additional options
        if (isset($options['crf'])) {
            $command .= " -crf {$options['crf']}";
        }
        
        if (isset($options['keyframe_interval'])) {
            $command .= " -g {$options['keyframe_interval']}";
        }
        
        // Output
        $command .= " -y \"{$outputPath}\"";
        
        return $command;
    }

    /**
     * Generate thumbnail
     */
    private function generateThumbnail($videoPath, $options = [])
    {
        $thumbnailPath = $this->getThumbnailPath($videoPath);
        $time = $options['thumbnail_time'] ?? '00:00:05';
        
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -ss {$time} -vframes 1 -q:v 2 -y \"{$thumbnailPath}\"";
        
        try {
            $result = Process::run($command);
            
            if ($result->successful() && file_exists($thumbnailPath)) {
                return [
                    'path' => $thumbnailPath,
                    'size' => filesize($thumbnailPath)
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to generate thumbnail: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate preview video
     */
    private function generatePreview($videoPath, $options = [])
    {
        $previewPath = $this->getPreviewPath($videoPath);
        $duration = $options['preview_duration'] ?? 30;
        
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -t {$duration} -c copy -y \"{$previewPath}\"";
        
        try {
            $result = Process::run($command);
            
            if ($result->successful() && file_exists($previewPath)) {
                return [
                    'path' => $previewPath,
                    'duration' => $duration
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to generate preview: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get output path for quality version
     */
    private function getOutputPath($inputPath, $quality)
    {
        $pathInfo = pathinfo($inputPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        return "{$directory}/{$filename}_{$quality}.mp4";
    }

    /**
     * Get thumbnail path
     */
    private function getThumbnailPath($videoPath)
    {
        $pathInfo = pathinfo($videoPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        return "{$directory}/{$filename}_thumb.jpg";
    }

    /**
     * Get preview path
     */
    private function getPreviewPath($videoPath)
    {
        $pathInfo = pathinfo($videoPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        return "{$directory}/{$filename}_preview.mp4";
    }

    /**
     * Compress video
     */
    public function compressVideo($inputPath, $targetSize, $options = [])
    {
        $videoInfo = $this->getVideoInfo($inputPath);
        $currentSize = $videoInfo['size'];
        
        if ($currentSize <= $targetSize) {
            return $inputPath; // Already small enough
        }
        
        $compressionRatio = $targetSize / $currentSize;
        $crf = $this->calculateCRF($compressionRatio);
        
        $outputPath = $this->getCompressedPath($inputPath);
        $settings = [
            'video_bitrate' => $this->calculateBitrate($targetSize, $videoInfo['duration']),
            'crf' => $crf
        ];
        
        $command = $this->buildFFmpegCommand($inputPath, $outputPath, $settings, $options);
        
        try {
            $result = Process::run($command);
            
            if ($result->successful() && file_exists($outputPath)) {
                return $outputPath;
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Video compression failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate CRF value for compression
     */
    private function calculateCRF($compressionRatio)
    {
        // CRF range: 18-28 (lower = better quality, higher = smaller size)
        $baseCRF = 23;
        $crfIncrease = (1 - $compressionRatio) * 10;
        
        return min(28, max(18, $baseCRF + $crfIncrease));
    }

    /**
     * Calculate target bitrate
     */
    private function calculateBitrate($targetSize, $duration)
    {
        $sizeInBits = $targetSize * 8;
        $durationInSeconds = $duration;
        
        // Reserve 20% for audio
        $videoBits = $sizeInBits * 0.8;
        $bitrate = $videoBits / $durationInSeconds;
        
        return round($bitrate / 1000) . 'k';
    }

    /**
     * Get compressed video path
     */
    private function getCompressedPath($inputPath)
    {
        $pathInfo = pathinfo($inputPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        return "{$directory}/{$filename}_compressed.mp4";
    }

    /**
     * Convert video format
     */
    public function convertFormat($inputPath, $outputFormat, $options = [])
    {
        $outputPath = $this->getConvertedPath($inputPath, $outputFormat);
        
        $command = "{$this->ffmpegPath} -i \"{$inputPath}\"";
        
        // Format-specific settings
        switch ($outputFormat) {
            case 'webm':
                $command .= " -c:v libvpx-vp9 -c:a libopus";
                break;
            case 'ogg':
                $command .= " -c:v libtheora -c:a libvorbis";
                break;
            default:
                $command .= " -c:v libx264 -c:a aac";
        }
        
        $command .= " -y \"{$outputPath}\"";
        
        try {
            $result = Process::run($command);
            
            if ($result->successful() && file_exists($outputPath)) {
                return $outputPath;
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error("Format conversion to {$outputFormat} failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get converted video path
     */
    private function getConvertedPath($inputPath, $outputFormat)
    {
        $pathInfo = pathinfo($inputPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        return "{$directory}/{$filename}.{$outputFormat}";
    }

    /**
     * Extract audio from video
     */
    public function extractAudio($videoPath, $audioFormat = 'mp3')
    {
        $audioPath = $this->getAudioPath($videoPath, $audioFormat);
        
        $command = "{$this->ffmpegPath} -i \"{$videoPath}\" -vn -c:a {$audioFormat} -b:a 128k -y \"{$audioPath}\"";
        
        try {
            $result = Process::run($command);
            
            if ($result->successful() && file_exists($audioPath)) {
                return $audioPath;
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Audio extraction failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get audio path
     */
    private function getAudioPath($videoPath, $audioFormat)
    {
        $pathInfo = pathinfo($videoPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        return "{$directory}/{$filename}.{$audioFormat}";
    }

    /**
     * Check if FFmpeg is available
     */
    public function isFFmpegAvailable()
    {
        try {
            $result = Process::run("{$this->ffmpegPath} -version");
            return $result->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get supported formats
     */
    public function getSupportedFormats()
    {
        return $this->outputFormats;
    }

    /**
     * Get quality presets
     */
    public function getQualityPresets()
    {
        return $this->qualityPresets;
    }
} 