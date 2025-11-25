<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Models\CourseContent;
use App\Models\ContentFormat;

class MultiFormatSupportService
{
    protected $supportedFormats = [
        'pdf' => [
            'mime_types' => ['application/pdf'],
            'extensions' => ['pdf'],
            'max_size' => 10240, // 10MB
            'processors' => ['pdf_parser', 'pdf_to_text', 'pdf_to_html']
        ],
        'doc' => [
            'mime_types' => ['application/msword'],
            'extensions' => ['doc'],
            'max_size' => 5120, // 5MB
            'processors' => ['antiword', 'catdoc', 'doc_to_text']
        ],
        'docx' => [
            'mime_types' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'extensions' => ['docx'],
            'max_size' => 5120, // 5MB
            'processors' => ['phpword', 'docx_to_text', 'docx_to_html']
        ],
        'ppt' => [
            'mime_types' => ['application/vnd.ms-powerpoint'],
            'extensions' => ['ppt'],
            'max_size' => 10240, // 10MB
            'processors' => ['catppt', 'ppt_to_text', 'ppt_to_html']
        ],
        'pptx' => [
            'mime_types' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'extensions' => ['pptx'],
            'max_size' => 10240, // 10MB
            'processors' => ['phppresentation', 'pptx_to_text', 'pptx_to_html']
        ],
        'xls' => [
            'mime_types' => ['application/vnd.ms-excel'],
            'extensions' => ['xls'],
            'max_size' => 5120, // 5MB
            'processors' => ['xls_to_csv', 'xls_to_text']
        ],
        'xlsx' => [
            'mime_types' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'extensions' => ['xlsx'],
            'max_size' => 5120, // 5MB
            'processors' => ['phpspreadsheet', 'xlsx_to_csv', 'xlsx_to_text']
        ],
        'txt' => [
            'mime_types' => ['text/plain'],
            'extensions' => ['txt'],
            'max_size' => 1024, // 1MB
            'processors' => ['text_processor']
        ],
        'rtf' => [
            'mime_types' => ['application/rtf', 'text/rtf'],
            'extensions' => ['rtf'],
            'max_size' => 2048, // 2MB
            'processors' => ['rtf_to_text', 'rtf_to_html']
        ]
    ];

    protected $contentProcessors = [
        'pdf_parser' => 'App\Services\ContentProcessors\PDFProcessor',
        'phpword' => 'App\Services\ContentProcessors\PHPWordProcessor',
        'phppresentation' => 'App\Services\ContentProcessors\PHPPresentationProcessor',
        'phpspreadsheet' => 'App\Services\ContentProcessors\PHPSpreadsheetProcessor',
        'text_processor' => 'App\Services\ContentProcessors\TextProcessor'
    ];

    /**
     * Process uploaded file
     */
    public function processFile($file, $courseId, $options = [])
    {
        try {
            // Validate file
            $validation = $this->validateFile($file);
            if (!$validation['valid']) {
                return $validation;
            }

            // Determine file format
            $format = $this->detectFileFormat($file);
            if (!$format) {
                return [
                    'success' => false,
                    'message' => 'Unsupported file format'
                ];
            }

            // Store original file
            $filePath = $this->storeFile($file, $courseId, $format);
            if (!$filePath) {
                return [
                    'success' => false,
                    'message' => 'Failed to store file'
                ];
            }

            // Process file content
            $processedContent = $this->processFileContent($file, $format, $options);

            // Create content record
            $content = CourseContent::create([
                'course_id' => $courseId,
                'title' => $options['title'] ?? $file->getClientOriginalName(),
                'description' => $options['description'] ?? '',
                'content_type' => 'document',
                'file_path' => $filePath,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'format' => $format,
                'processed_content' => $processedContent['content'] ?? null,
                'metadata' => array_merge($processedContent['metadata'] ?? [], [
                    'original_filename' => $file->getClientOriginalName(),
                    'processing_date' => now()->toISOString(),
                    'processing_options' => $options
                ])
            ]);

            Log::info("File processed successfully", [
                'content_id' => $content->id,
                'course_id' => $courseId,
                'format' => $format,
                'file_size' => $file->getSize()
            ]);

            return [
                'success' => true,
                'content' => $content,
                'processed_content' => $processedContent,
                'message' => 'File processed successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to process file: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to process file: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate file
     */
    private function validateFile($file)
    {
        // Check file size
        $maxSize = $this->getMaxFileSize($file);
        if ($file->getSize() > $maxSize * 1024) {
            return [
                'valid' => false,
                'message' => "File size exceeds maximum limit of {$maxSize}MB"
            ];
        }

        // Check file type
        $mimeType = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        
        $supported = false;
        foreach ($this->supportedFormats as $format => $config) {
            if (in_array($mimeType, $config['mime_types']) || in_array($extension, $config['extensions'])) {
                $supported = true;
                break;
            }
        }

        if (!$supported) {
            return [
                'valid' => false,
                'message' => "File type '{$extension}' is not supported"
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get maximum file size for format
     */
    private function getMaxFileSize($file)
    {
        $format = $this->detectFileFormat($file);
        return $format ? $this->supportedFormats[$format]['max_size'] : 1024;
    }

    /**
     * Detect file format
     */
    private function detectFileFormat($file)
    {
        $mimeType = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());

        foreach ($this->supportedFormats as $format => $config) {
            if (in_array($mimeType, $config['mime_types']) || in_array($extension, $config['extensions'])) {
                return $format;
            }
        }

        return null;
    }

    /**
     * Store file
     */
    private function storeFile($file, $courseId, $format)
    {
        try {
            $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
            $path = "courses/{$courseId}/documents/{$format}";
            
            $filePath = Storage::disk('public')->putFileAs($path, $file, $filename);
            
            return $filePath ? $filePath : null;

        } catch (\Exception $e) {
            Log::error("Failed to store file: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Process file content
     */
    private function processFileContent($file, $format, $options)
    {
        try {
            $processor = $this->getContentProcessor($format);
            if (!$processor) {
                return [
                    'content' => null,
                    'metadata' => ['error' => 'No processor available for format: ' . $format]
                ];
            }

            $processorInstance = new $processor();
            return $processorInstance->process($file, $options);

        } catch (\Exception $e) {
            Log::error("Failed to process file content: " . $e->getMessage());
            return [
                'content' => null,
                'metadata' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * Get content processor for format
     */
    private function getContentProcessor($format)
    {
        $processors = $this->supportedFormats[$format]['processors'] ?? [];
        
        foreach ($processors as $processor) {
            if (isset($this->contentProcessors[$processor])) {
                return $this->contentProcessors[$processor];
            }
        }

        return null;
    }

    /**
     * Convert file to different format
     */
    public function convertFile($contentId, $targetFormat, $options = [])
    {
        try {
            $content = CourseContent::find($contentId);
            if (!$content) {
                return [
                    'success' => false,
                    'message' => 'Content not found'
                ];
            }

            // Check if conversion is supported
            if (!$this->isConversionSupported($content->format, $targetFormat)) {
                return [
                    'success' => false,
                    'message' => "Conversion from {$content->format} to {$targetFormat} is not supported"
                ];
            }

            // Get converter
            $converter = $this->getFormatConverter($content->format, $targetFormat);
            if (!$converter) {
                return [
                    'success' => false,
                    'message' => 'No converter available for this conversion'
                ];
            }

            // Convert file
            $convertedContent = $converter->convert($content, $targetFormat, $options);

            // Store converted file
            $convertedFilePath = $this->storeConvertedFile($convertedContent, $content->course_id, $targetFormat);

            // Create new content record for converted file
            $newContent = CourseContent::create([
                'course_id' => $content->course_id,
                'title' => $content->title . " ({$targetFormat})",
                'description' => $content->description,
                'content_type' => 'document',
                'file_path' => $convertedFilePath,
                'file_size' => strlen($convertedContent),
                'mime_type' => $this->getMimeTypeForFormat($targetFormat),
                'format' => $targetFormat,
                'processed_content' => $convertedContent,
                'metadata' => array_merge($content->metadata ?? [], [
                    'converted_from' => $content->id,
                    'conversion_date' => now()->toISOString(),
                    'conversion_options' => $options
                ])
            ]);

            Log::info("File converted successfully", [
                'original_content_id' => $contentId,
                'new_content_id' => $newContent->id,
                'from_format' => $content->format,
                'to_format' => $targetFormat
            ]);

            return [
                'success' => true,
                'content' => $newContent,
                'message' => 'File converted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to convert file: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to convert file: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if conversion is supported
     */
    private function isConversionSupported($fromFormat, $toFormat)
    {
        $supportedConversions = [
            'pdf' => ['txt', 'html'],
            'doc' => ['txt', 'html', 'pdf'],
            'docx' => ['txt', 'html', 'pdf'],
            'ppt' => ['txt', 'html', 'pdf'],
            'pptx' => ['txt', 'html', 'pdf'],
            'xls' => ['csv', 'txt'],
            'xlsx' => ['csv', 'txt', 'pdf']
        ];

        return isset($supportedConversions[$fromFormat]) && 
               in_array($toFormat, $supportedConversions[$fromFormat]);
    }

    /**
     * Get format converter
     */
    private function getFormatConverter($fromFormat, $toFormat)
    {
        $converters = [
            'pdf_to_txt' => 'App\Services\FormatConverters\PDFToTextConverter',
            'pdf_to_html' => 'App\Services\FormatConverters\PDFToHTMLConverter',
            'doc_to_txt' => 'App\Services\FormatConverters\DocToTextConverter',
            'doc_to_html' => 'App\Services\FormatConverters\DocToHTMLConverter',
            'doc_to_pdf' => 'App\Services\FormatConverters\DocToPDFConverter',
            'docx_to_txt' => 'App\Services\FormatConverters\DocxToTextConverter',
            'docx_to_html' => 'App\Services\FormatConverters\DocxToHTMLConverter',
            'docx_to_pdf' => 'App\Services\FormatConverters\DocxToPDFConverter',
            'ppt_to_txt' => 'App\Services\FormatConverters\PPTToTextConverter',
            'ppt_to_html' => 'App\Services\FormatConverters\PPTToHTMLConverter',
            'ppt_to_pdf' => 'App\Services\FormatConverters\PPTToPDFConverter',
            'pptx_to_txt' => 'App\Services\FormatConverters\PPTxToTextConverter',
            'pptx_to_html' => 'App\Services\FormatConverters\PPTxToHTMLConverter',
            'pptx_to_pdf' => 'App\Services\FormatConverters\PPTxToPDFConverter',
            'xls_to_csv' => 'App\Services\FormatConverters\XlsToCSVConverter',
            'xls_to_txt' => 'App\Services\FormatConverters\XlsToTextConverter',
            'xlsx_to_csv' => 'App\Services\FormatConverters\XlsxToCSVConverter',
            'xlsx_to_txt' => 'App\Services\FormatConverters\XlsxToTextConverter',
            'xlsx_to_pdf' => 'App\Services\FormatConverters\XlsxToPDFConverter'
        ];

        $converterKey = "{$fromFormat}_to_{$toFormat}";
        return isset($converters[$converterKey]) ? $converters[$converterKey] : null;
    }

    /**
     * Store converted file
     */
    private function storeConvertedFile($content, $courseId, $format)
    {
        try {
            $filename = Str::random(40) . '.' . $format;
            $path = "courses/{$courseId}/documents/{$format}";
            
            $filePath = Storage::disk('public')->putFileAs($path, $content, $filename);
            
            return $filePath ? $filePath : null;

        } catch (\Exception $e) {
            Log::error("Failed to store converted file: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get MIME type for format
     */
    private function getMimeTypeForFormat($format)
    {
        $mimeTypes = [
            'txt' => 'text/plain',
            'html' => 'text/html',
            'csv' => 'text/csv',
            'pdf' => 'application/pdf'
        ];

        return $mimeTypes[$format] ?? 'application/octet-stream';
    }

    /**
     * Extract text from file
     */
    public function extractText($contentId)
    {
        try {
            $content = CourseContent::find($contentId);
            if (!$content) {
                return [
                    'success' => false,
                    'message' => 'Content not found'
                ];
            }

            // Check if text is already extracted
            if (isset($content->metadata['extracted_text'])) {
                return [
                    'success' => true,
                    'text' => $content->metadata['extracted_text'],
                    'message' => 'Text already extracted'
                ];
            }

            // Extract text based on format
            $extractor = $this->getTextExtractor($content->format);
            if (!$extractor) {
                return [
                    'success' => false,
                    'message' => 'No text extractor available for format: ' . $content->format
                ];
            }

            $extractorInstance = new $extractor();
            $extractedText = $extractorInstance->extract($content);

            // Update content metadata
            $metadata = $content->metadata ?? [];
            $metadata['extracted_text'] = $extractedText;
            $metadata['text_extraction_date'] = now()->toISOString();
            
            $content->metadata = $metadata;
            $content->save();

            return [
                'success' => true,
                'text' => $extractedText,
                'message' => 'Text extracted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to extract text: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to extract text: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get text extractor for format
     */
    private function getTextExtractor($format)
    {
        $extractors = [
            'pdf' => 'App\Services\TextExtractors\PDFTextExtractor',
            'doc' => 'App\Services\TextExtractors\DocTextExtractor',
            'docx' => 'App\Services\TextExtractors\DocxTextExtractor',
            'ppt' => 'App\Services\TextExtractors\PPTTextExtractor',
            'pptx' => 'App\Services\TextExtractors\PPTxTextExtractor',
            'xls' => 'App\Services\TextExtractors\XlsTextExtractor',
            'xlsx' => 'App\Services\TextExtractors\XlsxTextExtractor',
            'txt' => 'App\Services\TextExtractors\TextTextExtractor',
            'rtf' => 'App\Services\TextExtractors\RTFTextExtractor'
        ];

        return isset($extractors[$format]) ? $extractors[$format] : null;
    }

    /**
     * Search content
     */
    public function searchContent($query, $courseId = null, $filters = [])
    {
        try {
            $searchQuery = CourseContent::query();

            if ($courseId) {
                $searchQuery->where('course_id', $courseId);
            }

            // Apply format filters
            if (isset($filters['format'])) {
                $searchQuery->where('format', $filters['format']);
            }

            // Apply content type filters
            if (isset($filters['content_type'])) {
                $searchQuery->where('content_type', $filters['content_type']);
            }

            // Search in title and description
            $searchQuery->where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%");
            });

            // Search in processed content if available
            $searchQuery->orWhere('processed_content', 'LIKE', "%{$query}%");

            // Search in extracted text if available
            $searchQuery->orWhereRaw("JSON_EXTRACT(metadata, '$.extracted_text') LIKE ?", ["%{$query}%"]);

            $results = $searchQuery->with(['course'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return [
                'success' => true,
                'results' => $results,
                'total_found' => $results->total()
            ];

        } catch (\Exception $e) {
            Log::error("Failed to search content: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to search content'
            ];
        }
    }

    /**
     * Get supported formats
     */
    public function getSupportedFormats()
    {
        return $this->supportedFormats;
    }

    /**
     * Get format information
     */
    public function getFormatInfo($format)
    {
        return $this->supportedFormats[$format] ?? null;
    }

    /**
     * Check if format is supported
     */
    public function isFormatSupported($format)
    {
        return isset($this->supportedFormats[$format]);
    }

    /**
     * Get content statistics
     */
    public function getContentStats($courseId = null)
    {
        $query = CourseContent::query();

        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        $stats = [
            'total_content' => $query->count(),
            'content_by_format' => $query->selectRaw('format, COUNT(*) as count')
                ->groupBy('format')
                ->pluck('count', 'format'),
            'content_by_type' => $query->selectRaw('content_type, COUNT(*) as count')
                ->groupBy('content_type')
                ->pluck('count', 'content_type'),
            'total_file_size' => $query->sum('file_size'),
            'average_file_size' => round($query->avg('file_size'), 2),
            'formats_with_most_content' => $query->selectRaw('format, COUNT(*) as count')
                ->groupBy('format')
                ->orderBy('count', 'desc')
                ->limit(5)
                ->pluck('count', 'format')
        ];

        return $stats;
    }

    /**
     * Clean up old content
     */
    public function cleanupOldContent($daysOld = 30)
    {
        try {
            $oldContent = CourseContent::where('created_at', '<', now()->subDays($daysOld))
                ->where('course_id', null) // Orphaned content
                ->get();

            $deletedCount = 0;

            foreach ($oldContent as $content) {
                try {
                    // Delete file from storage
                    if ($content->file_path && Storage::disk('public')->exists($content->file_path)) {
                        Storage::disk('public')->delete($content->file_path);
                    }

                    // Delete content record
                    $content->delete();
                    $deletedCount++;

                } catch (\Exception $e) {
                    Log::warning("Failed to delete old content {$content->id}: " . $e->getMessage());
                }
            }

            Log::info("Cleanup completed", [
                'total_old_content' => $oldContent->count(),
                'deleted_count' => $deletedCount
            ]);

            return [
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "Cleaned up {$deletedCount} old content items"
            ];

        } catch (\Exception $e) {
            Log::error("Failed to cleanup old content: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to cleanup old content'
            ];
        }
    }
} 