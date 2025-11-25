<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use App\Models\Document;

class DocumentProcessingService
{
    protected $supportedFormats = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'rtf'];
    protected $conversionFormats = ['pdf', 'html', 'txt'];
    
    public function __construct()
    {
        // Check if required tools are available
        $this->checkDependencies();
    }

    /**
     * Process uploaded document
     */
    public function processDocument($filePath, $options = [])
    {
        try {
            $fileInfo = pathinfo($filePath);
            $extension = strtolower($fileInfo['extension']);
            
            if (!in_array($extension, $this->supportedFormats)) {
                throw new \Exception("Unsupported document format: {$extension}");
            }

            $document = [
                'original_path' => $filePath,
                'original_format' => $extension,
                'file_size' => filesize($filePath),
                'processed_versions' => [],
                'metadata' => $this->extractMetadata($filePath, $extension),
                'text_content' => $this->extractTextContent($filePath, $extension),
                'preview_images' => $this->generatePreviewImages($filePath, $extension)
            ];

            // Generate different format versions
            foreach ($this->conversionFormats as $targetFormat) {
                if ($targetFormat !== $extension) {
                    $convertedPath = $this->convertDocument($filePath, $targetFormat, $options);
                    if ($convertedPath) {
                        $document['processed_versions'][$targetFormat] = $convertedPath;
                    }
                }
            }

            // Generate searchable text
            $document['searchable_text'] = $this->generateSearchableText($document['text_content']);

            return $document;

        } catch (\Exception $e) {
            Log::error('Document processing failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract document metadata
     */
    private function extractMetadata($filePath, $format)
    {
        $metadata = [
            'format' => $format,
            'file_size' => filesize($filePath),
            'created_at' => filectime($filePath),
            'modified_at' => filemtime($filePath)
        ];

        switch ($format) {
            case 'pdf':
                $metadata = array_merge($metadata, $this->extractPDFMetadata($filePath));
                break;
            case 'doc':
            case 'docx':
                $metadata = array_merge($metadata, $this->extractWordMetadata($filePath));
                break;
            case 'ppt':
            case 'pptx':
                $metadata = array_merge($metadata, $this->extractPowerPointMetadata($filePath));
                break;
            case 'xls':
            case 'xlsx':
                $metadata = array_merge($metadata, $this->extractExcelMetadata($filePath));
                break;
        }

        return $metadata;
    }

    /**
     * Extract PDF metadata
     */
    private function extractPDFMetadata($filePath)
    {
        try {
            $command = "pdfinfo \"{$filePath}\"";
            $result = Process::run($command);

            if ($result->successful()) {
                $output = $result->output();
                $metadata = [];

                // Parse pdfinfo output
                preg_match_all('/^([^:]+):\s*(.+)$/m', $output, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $key = strtolower(trim($match[1]));
                    $value = trim($match[2]);
                    
                    switch ($key) {
                        case 'title':
                            $metadata['title'] = $value;
                            break;
                        case 'author':
                            $metadata['author'] = $value;
                            break;
                        case 'subject':
                            $metadata['subject'] = $value;
                            break;
                        case 'creator':
                            $metadata['creator'] = $value;
                            break;
                        case 'producer':
                            $metadata['producer'] = $value;
                            break;
                        case 'pages':
                            $metadata['page_count'] = (int) $value;
                            break;
                        case 'file size':
                            $metadata['file_size_bytes'] = (int) preg_replace('/[^0-9]/', '', $value);
                            break;
                    }
                }

                return $metadata;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract PDF metadata: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Extract Word document metadata
     */
    private function extractWordMetadata($filePath)
    {
        try {
            if (class_exists('ZipArchive')) {
                // For DOCX files
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    $xml = $zip->getFromName('docProps/core.xml');
                    $zip->close();

                    if ($xml) {
                        $dom = new \DOMDocument();
                        $dom->loadXML($xml);
                        
                        $metadata = [];
                        $properties = [
                            'dc:title' => 'title',
                            'dc:creator' => 'author',
                            'dc:subject' => 'subject',
                            'cp:lastModifiedBy' => 'last_modified_by',
                            'dcterms:created' => 'created_date',
                            'dcterms:modified' => 'modified_date'
                        ];

                        foreach ($properties as $xpath => $key) {
                            $nodes = $dom->getElementsByTagName($xpath);
                            if ($nodes->length > 0) {
                                $metadata[$key] = $nodes->item(0)->textContent;
                            }
                        }

                        return $metadata;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract Word metadata: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Extract PowerPoint metadata
     */
    private function extractPowerPointMetadata($filePath)
    {
        try {
            if (class_exists('ZipArchive')) {
                // For PPTX files
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    $xml = $zip->getFromName('docProps/core.xml');
                    $zip->close();

                    if ($xml) {
                        $dom = new \DOMDocument();
                        $dom->loadXML($xml);
                        
                        $metadata = [];
                        $properties = [
                            'dc:title' => 'title',
                            'dc:creator' => 'author',
                            'dc:subject' => 'subject',
                            'cp:lastModifiedBy' => 'last_modified_by',
                            'dcterms:created' => 'created_date',
                            'dcterms:modified' => 'modified_date'
                        ];

                        foreach ($properties as $xpath => $key) {
                            $nodes = $dom->getElementsByTagName($xpath);
                            if ($nodes->length > 0) {
                                $metadata[$key] = $nodes->item(0)->textContent;
                            }
                        }

                        return $metadata;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract PowerPoint metadata: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Extract Excel metadata
     */
    private function extractExcelMetadata($filePath)
    {
        try {
            if (class_exists('ZipArchive')) {
                // For XLSX files
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    $xml = $zip->getFromName('docProps/core.xml');
                    $zip->close();

                    if ($xml) {
                        $dom = new \DOMDocument();
                        $dom->loadXML($xml);
                        
                        $metadata = [];
                        $properties = [
                            'dc:title' => 'title',
                            'dc:creator' => 'author',
                            'dc:subject' => 'subject',
                            'cp:lastModifiedBy' => 'last_modified_by',
                            'dcterms:created' => 'created_date',
                            'dcterms:modified' => 'modified_date'
                        ];

                        foreach ($properties as $xpath => $key) {
                            $nodes = $dom->getElementsByTagName($xpath);
                            if ($nodes->length > 0) {
                                $metadata[$key] = $nodes->item(0)->textContent;
                            }
                        }

                        return $metadata;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract Excel metadata: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Extract text content from document
     */
    private function extractTextContent($filePath, $format)
    {
        try {
            switch ($format) {
                case 'pdf':
                    return $this->extractPDFText($filePath);
                case 'doc':
                case 'docx':
                    return $this->extractWordText($filePath);
                case 'ppt':
                case 'pptx':
                    return $this->extractPowerPointText($filePath);
                case 'xls':
                case 'xlsx':
                    return $this->extractExcelText($filePath);
                case 'txt':
                case 'rtf':
                    return file_get_contents($filePath);
                default:
                    return '';
            }
        } catch (\Exception $e) {
            Log::warning("Failed to extract text from {$format} file: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Extract text from PDF
     */
    private function extractPDFText($filePath)
    {
        try {
            $command = "pdftotext \"{$filePath}\" -";
            $result = Process::run($command);

            if ($result->successful()) {
                return $result->output();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract PDF text: ' . $e->getMessage());
        }

        return '';
    }

    /**
     * Extract text from Word document
     */
    private function extractWordText($filePath)
    {
        try {
            if (class_exists('ZipArchive')) {
                // For DOCX files
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    $xml = $zip->getFromName('word/document.xml');
                    $zip->close();

                    if ($xml) {
                        $dom = new \DOMDocument();
                        $dom->loadXML($xml);
                        
                        $textNodes = $dom->getElementsByTagName('w:t');
                        $text = '';
                        
                        foreach ($textNodes as $node) {
                            $text .= $node->textContent . ' ';
                        }

                        return trim($text);
                    }
                }
            }

            // For DOC files, try using antiword
            $command = "antiword \"{$filePath}\"";
            $result = Process::run($command);

            if ($result->successful()) {
                return $result->output();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract Word text: ' . $e->getMessage());
        }

        return '';
    }

    /**
     * Extract text from PowerPoint
     */
    private function extractPowerPointText($filePath)
    {
        try {
            if (class_exists('ZipArchive')) {
                // For PPTX files
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    $text = '';
                    
                    // Extract text from all slides
                    for ($i = 1; $i <= 100; $i++) { // Limit to 100 slides
                        $slideXml = $zip->getFromName("ppt/slides/slide{$i}.xml");
                        if (!$slideXml) break;
                        
                        $dom = new \DOMDocument();
                        $dom->loadXML($slideXml);
                        
                        $textNodes = $dom->getElementsByTagName('a:t');
                        foreach ($textNodes as $node) {
                            $text .= $node->textContent . ' ';
                        }
                    }
                    
                    $zip->close();
                    return trim($text);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract PowerPoint text: ' . $e->getMessage());
        }

        return '';
    }

    /**
     * Extract text from Excel
     */
    private function extractExcelText($filePath)
    {
        try {
            if (class_exists('ZipArchive')) {
                // For XLSX files
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    $text = '';
                    
                    // Extract text from all worksheets
                    $worksheets = $zip->getFromName('xl/workbook.xml');
                    if ($worksheets) {
                        $dom = new \DOMDocument();
                        $dom->loadXML($worksheets);
                        
                        $sheetNodes = $dom->getElementsByTagName('sheet');
                        foreach ($sheetNodes as $sheet) {
                            $sheetName = $sheet->getAttribute('name');
                            $sheetXml = $zip->getFromName("xl/worksheets/sheet1.xml");
                            
                            if ($sheetXml) {
                                $sheetDom = new \DOMDocument();
                                $sheetDom->loadXML($sheetXml);
                                
                                $cellNodes = $sheetDom->getElementsByTagName('c');
                                foreach ($cellNodes as $cell) {
                                    $value = $cell->getAttribute('v');
                                    if ($value) {
                                        $text .= $value . ' ';
                                    }
                                }
                            }
                        }
                    }
                    
                    $zip->close();
                    return trim($text);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract Excel text: ' . $e->getMessage());
        }

        return '';
    }

    /**
     * Generate preview images
     */
    private function generatePreviewImages($filePath, $format)
    {
        $previews = [];

        try {
            switch ($format) {
                case 'pdf':
                    $previews = $this->generatePDFPreviews($filePath);
                    break;
                case 'doc':
                case 'docx':
                    $previews = $this->generateWordPreviews($filePath);
                    break;
                case 'ppt':
                case 'pptx':
                    $previews = $this->generatePowerPointPreviews($filePath);
                    break;
            }
        } catch (\Exception $e) {
            Log::warning("Failed to generate previews for {$format} file: " . $e->getMessage());
        }

        return $previews;
    }

    /**
     * Generate PDF preview images
     */
    private function generatePDFPreviews($filePath)
    {
        $previews = [];
        $outputDir = dirname($filePath) . '/previews';
        
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        try {
            // Generate preview for first few pages
            for ($page = 1; $page <= 3; $page++) {
                $outputFile = "{$outputDir}/page_{$page}.png";
                
                $command = "pdftoppm -png -f {$page} -l {$page} \"{$filePath}\" \"{$outputDir}/page_{$page}\"";
                $result = Process::run($command);

                if ($result->successful() && file_exists($outputFile)) {
                    $previews[] = [
                        'page' => $page,
                        'path' => $outputFile,
                        'type' => 'image/png'
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to generate PDF previews: ' . $e->getMessage());
        }

        return $previews;
    }

    /**
     * Generate Word document previews
     */
    private function generateWordPreviews($filePath)
    {
        // This would require additional tools like LibreOffice or Pandoc
        // For now, return empty array
        return [];
    }

    /**
     * Generate PowerPoint previews
     */
    private function generatePowerPointPreviews($filePath)
    {
        // This would require additional tools like LibreOffice or Pandoc
        // For now, return empty array
        return [];
    }

    /**
     * Convert document to different format
     */
    private function convertDocument($filePath, $targetFormat, $options = [])
    {
        try {
            $outputPath = $this->getOutputPath($filePath, $targetFormat);
            
            switch ($targetFormat) {
                case 'pdf':
                    return $this->convertToPDF($filePath, $outputPath);
                case 'html':
                    return $this->convertToHTML($filePath, $outputPath);
                case 'txt':
                    return $this->convertToText($filePath, $outputPath);
                default:
                    return null;
            }
        } catch (\Exception $e) {
            Log::warning("Failed to convert document to {$targetFormat}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert document to PDF
     */
    private function convertToPDF($inputPath, $outputPath)
    {
        try {
            $inputFormat = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
            
            switch ($inputFormat) {
                case 'doc':
                case 'docx':
                    $command = "soffice --headless --convert-to pdf \"{$inputPath}\" --outdir \"" . dirname($outputPath) . "\"";
                    break;
                case 'ppt':
                case 'pptx':
                    $command = "soffice --headless --convert-to pdf \"{$inputPath}\" --outdir \"" . dirname($outputPath) . "\"";
                    break;
                case 'xls':
                case 'xlsx':
                    $command = "soffice --headless --convert-to pdf \"{$inputPath}\" --outdir \"" . dirname($outputPath) . "\"";
                    break;
                default:
                    return null;
            }

            $result = Process::run($command);

            if ($result->successful()) {
                $convertedFile = dirname($outputPath) . '/' . pathinfo($inputPath, PATHINFO_FILENAME) . '.pdf';
                if (file_exists($convertedFile)) {
                    rename($convertedFile, $outputPath);
                    return $outputPath;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to convert to PDF: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Convert document to HTML
     */
    private function convertToHTML($inputPath, $outputPath)
    {
        try {
            $inputFormat = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
            
            switch ($inputFormat) {
                case 'doc':
                case 'docx':
                    $command = "pandoc \"{$inputPath}\" -o \"{$outputPath}\"";
                    break;
                case 'ppt':
                case 'pptx':
                    $command = "pandoc \"{$inputPath}\" -o \"{$outputPath}\"";
                    break;
                default:
                    return null;
            }

            $result = Process::run($command);

            if ($result->successful() && file_exists($outputPath)) {
                return $outputPath;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to convert to HTML: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Convert document to text
     */
    private function convertToText($inputPath, $outputPath)
    {
        $textContent = $this->extractTextContent($inputPath, strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)));
        
        if ($textContent) {
            file_put_contents($outputPath, $textContent);
            return $outputPath;
        }

        return null;
    }

    /**
     * Get output path for converted document
     */
    private function getOutputPath($inputPath, $targetFormat)
    {
        $pathInfo = pathinfo($inputPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        return "{$directory}/{$filename}.{$targetFormat}";
    }

    /**
     * Generate searchable text
     */
    private function generateSearchableText($textContent)
    {
        // Clean and normalize text for search
        $searchableText = preg_replace('/\s+/', ' ', $textContent); // Remove extra whitespace
        $searchableText = strip_tags($searchableText); // Remove HTML tags
        $searchableText = strtolower($searchableText); // Convert to lowercase
        
        return trim($searchableText);
    }

    /**
     * Check dependencies
     */
    private function checkDependencies()
    {
        $dependencies = [
            'pdftotext' => 'poppler-utils',
            'pdfinfo' => 'poppler-utils',
            'pdftoppm' => 'poppler-utils',
            'soffice' => 'libreoffice',
            'pandoc' => 'pandoc',
            'antiword' => 'antiword'
        ];

        $missing = [];

        foreach ($dependencies as $command => $package) {
            $result = Process::run("which {$command}");
            if (!$result->successful()) {
                $missing[] = $package;
            }
        }

        if (!empty($missing)) {
            Log::warning('Missing document processing dependencies: ' . implode(', ', $missing));
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
     * Get conversion formats
     */
    public function getConversionFormats()
    {
        return $this->conversionFormats;
    }

    /**
     * Check if format is supported
     */
    public function isFormatSupported($format)
    {
        return in_array(strtolower($format), $this->supportedFormats);
    }

    /**
     * Get document info
     */
    public function getDocumentInfo($filePath)
    {
        $fileInfo = pathinfo($filePath);
        $extension = strtolower($fileInfo['extension']);
        
        return [
            'filename' => $fileInfo['basename'],
            'extension' => $extension,
            'supported' => $this->isFormatSupported($extension),
            'file_size' => filesize($filePath),
            'mime_type' => mime_content_type($filePath)
        ];
    }
} 