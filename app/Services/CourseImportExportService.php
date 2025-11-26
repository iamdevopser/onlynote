<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\CourseLesson;
use App\Models\Category;
use App\Models\User;

class CourseImportExportService
{
    protected $supportedFormats = ['json', 'csv', 'xml', 'excel'];
    protected $maxFileSize = 10240; // 10MB
    protected $allowedMimeTypes = [
        'application/json',
        'text/csv',
        'application/xml',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel'
    ];

    /**
     * Export course
     */
    public function exportCourse($courseId, $format = 'json', $includeContent = false)
    {
        try {
            $course = Course::with(['sections.lessons', 'category', 'instructor'])->find($courseId);
            
            if (!$course) {
                return [
                    'success' => false,
                    'message' => 'Course not found'
                ];
            }

            $exportData = $this->prepareCourseData($course, $includeContent);

            switch ($format) {
                case 'json':
                    return $this->exportToJSON($exportData, $course->title);
                case 'csv':
                    return $this->exportToCSV($exportData, $course->title);
                case 'xml':
                    return $this->exportToXML($exportData, $course->title);
                case 'excel':
                    return $this->exportToExcel($exportData, $course->title);
                default:
                    return $this->exportToJSON($exportData, $course->title);
            }

        } catch (\Exception $e) {
            Log::error("Failed to export course: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to export course'
            ];
        }
    }

    /**
     * Export multiple courses
     */
    public function exportCourses($courseIds, $format = 'json', $includeContent = false)
    {
        try {
            $courses = Course::with(['sections.lessons', 'category', 'instructor'])
                ->whereIn('id', $courseIds)
                ->get();

            if ($courses->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No courses found'
                ];
            }

            $exportData = [];
            foreach ($courses as $course) {
                $exportData[] = $this->prepareCourseData($course, $includeContent);
            }

            $filename = 'courses_export_' . now()->format('Y-m-d_H-i-s');

            switch ($format) {
                case 'json':
                    return $this->exportToJSON($exportData, $filename);
                case 'csv':
                    return $this->exportToCSV($exportData, $filename);
                case 'xml':
                    return $this->exportToXML($exportData, $filename);
                case 'excel':
                    return $this->exportToExcel($exportData, $filename);
                default:
                    return $this->exportToJSON($exportData, $filename);
            }

        } catch (\Exception $e) {
            Log::error("Failed to export courses: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to export courses'
            ];
        }
    }

    /**
     * Import course
     */
    public function importCourse($file, $instructorId, $options = [])
    {
        try {
            // Validate file
            $validation = $this->validateImportFile($file);
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

            // Parse file content
            $importData = $this->parseImportFile($file, $format);
            if (!$importData['success']) {
                return $importData;
            }

            // Validate import data
            $dataValidation = $this->validateImportData($importData['data']);
            if (!$dataValidation['valid']) {
                return $dataValidation;
            }

            // Import course
            $course = $this->createCourseFromImport($importData['data'], $instructorId, $options);

            Log::info("Course imported successfully", [
                'course_id' => $course->id,
                'instructor_id' => $instructorId,
                'format' => $format
            ]);

            return [
                'success' => true,
                'course' => $course,
                'message' => 'Course imported successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to import course: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to import course: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Import multiple courses
     */
    public function importCourses($file, $instructorId, $options = [])
    {
        try {
            // Validate file
            $validation = $this->validateImportFile($file);
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

            // Parse file content
            $importData = $this->parseImportFile($file, $format);
            if (!$importData['success']) {
                return $importData;
            }

            // Check if it's a single course or multiple courses
            $coursesData = is_array($importData['data']) && isset($importData['data'][0]) 
                ? $importData['data'] 
                : [$importData['data']];

            $importedCourses = [];
            $errors = [];

            foreach ($coursesData as $index => $courseData) {
                try {
                    // Validate individual course data
                    $dataValidation = $this->validateImportData($courseData);
                    if (!$dataValidation['valid']) {
                        $errors[] = "Course " . ($index + 1) . ": " . implode(', ', $dataValidation['errors']);
                        continue;
                    }

                    // Import course
                    $course = $this->createCourseFromImport($courseData, $instructorId, $options);
                    $importedCourses[] = $course;

                } catch (\Exception $e) {
                    $errors[] = "Course " . ($index + 1) . ": " . $e->getMessage();
                }
            }

            $result = [
                'success' => !empty($importedCourses),
                'imported_courses' => $importedCourses,
                'total_imported' => count($importedCourses),
                'total_attempted' => count($coursesData),
                'errors' => $errors
            ];

            if (!empty($importedCourses)) {
                $result['message'] = "Successfully imported " . count($importedCourses) . " courses";
            } else {
                $result['message'] = "Failed to import any courses";
            }

            Log::info("Courses import completed", [
                'instructor_id' => $instructorId,
                'total_imported' => count($importedCourses),
                'total_attempted' => count($coursesData),
                'errors' => count($errors)
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error("Failed to import courses: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to import courses: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Prepare course data for export
     */
    private function prepareCourseData($course, $includeContent = false)
    {
        $data = [
            'id' => $course->id,
            'title' => $course->title,
            'description' => $course->description,
            'category' => $course->category->name ?? null,
            'difficulty_level' => $course->difficulty_level,
            'estimated_duration' => $course->estimated_duration,
            'target_audience' => $course->target_audience,
            'learning_objectives' => $course->learning_objectives,
            'prerequisites' => $course->prerequisites,
            'price' => $course->price,
            'currency' => $course->currency,
            'status' => $course->status,
            'metadata' => $course->metadata,
            'sections' => []
        ];

        foreach ($course->sections as $section) {
            $sectionData = [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'order' => $section->order,
                'is_free' => $section->is_free,
                'lessons' => []
            ];

            foreach ($section->lessons as $lesson) {
                $lessonData = [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'content_type' => $lesson->content_type,
                    'order' => $lesson->order,
                    'duration' => $lesson->duration,
                    'is_free' => $lesson->is_free
                ];

                if ($includeContent) {
                    $lessonData['content'] = $lesson->content;
                    $lessonData['video_url'] = $lesson->video_url;
                    $lessonData['attachments'] = $lesson->attachments;
                }

                $sectionData['lessons'][] = $lessonData;
            }

            $data['sections'][] = $sectionData;
        }

        return $data;
    }

    /**
     * Validate import file
     */
    private function validateImportFile($file)
    {
        // Check file size
        if ($file->getSize() > $this->maxFileSize * 1024) {
            return [
                'valid' => false,
                'message' => "File size exceeds maximum limit of {$this->maxFileSize}MB"
            ];
        }

        // Check file type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $this->allowedMimeTypes)) {
            return [
                'valid' => false,
                'message' => "File type '{$mimeType}' is not supported"
            ];
        }

        return ['valid' => true];
    }

    /**
     * Detect file format
     */
    private function detectFileFormat($file)
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        if ($extension === 'json' || $mimeType === 'application/json') {
            return 'json';
        }

        if ($extension === 'csv' || $mimeType === 'text/csv') {
            return 'csv';
        }

        if ($extension === 'xml' || $mimeType === 'application/xml') {
            return 'xml';
        }

        if (in_array($extension, ['xlsx', 'xls']) || strpos($mimeType, 'spreadsheet') !== false) {
            return 'excel';
        }

        return null;
    }

    /**
     * Parse import file
     */
    private function parseImportFile($file, $format)
    {
        try {
            $content = file_get_contents($file->getRealPath());

            switch ($format) {
                case 'json':
                    $data = json_decode($content, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return [
                            'success' => false,
                            'message' => 'Invalid JSON format: ' . json_last_error_msg()
                        ];
                    }
                    break;

                case 'csv':
                    $data = $this->parseCSV($content);
                    break;

                case 'xml':
                    $data = $this->parseXML($content);
                    break;

                case 'excel':
                    $data = $this->parseExcel($file);
                    break;

                default:
                    return [
                        'success' => false,
                        'message' => 'Unsupported format'
                    ];
            }

            return [
                'success' => true,
                'data' => $data
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to parse file: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Parse CSV content
     */
    private function parseCSV($content)
    {
        $lines = explode("\n", $content);
        $headers = str_getcsv(array_shift($lines));
        $data = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $values = str_getcsv($line);
            $row = array_combine($headers, $values);
            
            if ($row) {
                $data[] = $row;
            }
        }

        return $data;
    }

    /**
     * Parse XML content
     */
    private function parseXML($content)
    {
        $xml = simplexml_load_string($content);
        if (!$xml) {
            throw new \Exception('Invalid XML format');
        }

        return $this->xmlToArray($xml);
    }

    /**
     * Convert XML to array
     */
    private function xmlToArray($xml)
    {
        $array = json_decode(json_encode($xml), true);
        return $array;
    }

    /**
     * Parse Excel file
     */
    private function parseExcel($file)
    {
        // This would require a package like PhpSpreadsheet
        // For now, return CSV format
        $content = file_get_contents($file->getRealPath());
        return $this->parseCSV($content);
    }

    /**
     * Validate import data
     */
    private function validateImportData($data)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|string',
            'difficulty_level' => 'required|in:beginner,intermediate,advanced,expert',
            'estimated_duration' => 'required|integer|min:1',
            'target_audience' => 'array',
            'learning_objectives' => 'array',
            'prerequisites' => 'array',
            'price' => 'numeric|min:0',
            'sections' => 'array'
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->all()
            ];
        }

        // Validate sections if present
        if (isset($data['sections']) && is_array($data['sections'])) {
            foreach ($data['sections'] as $index => $section) {
                $sectionRules = [
                    'title' => 'required|string|max:255',
                    'description' => 'string',
                    'order' => 'integer|min:0',
                    'lessons' => 'array'
                ];

                $sectionValidator = Validator::make($section, $sectionRules);
                if ($sectionValidator->fails()) {
                    return [
                        'valid' => false,
                        'errors' => ["Section " . ($index + 1) . ": " . implode(', ', $sectionValidator->errors()->all())]
                    ];
                }

                // Validate lessons if present
                if (isset($section['lessons']) && is_array($section['lessons'])) {
                    foreach ($section['lessons'] as $lessonIndex => $lesson) {
                        $lessonRules = [
                            'title' => 'required|string|max:255',
                            'description' => 'string',
                            'content_type' => 'required|in:video,text,quiz,assignment',
                            'order' => 'integer|min:0',
                            'duration' => 'integer|min:0'
                        ];

                        $lessonValidator = Validator::make($lesson, $lessonRules);
                        if ($lessonValidator->fails()) {
                            return [
                                'valid' => false,
                                'errors' => ["Section " . ($index + 1) . " Lesson " . ($lessonIndex + 1) . ": " . implode(', ', $lessonValidator->errors()->all())]
                            ];
                        }
                    }
                }
            }
        }

        return ['valid' => true];
    }

    /**
     * Create course from import data
     */
    private function createCourseFromImport($data, $instructorId, $options)
    {
        DB::beginTransaction();

        try {
            // Find or create category
            $category = Category::firstOrCreate(
                ['name' => $data['category']],
                ['description' => 'Imported category', 'is_active' => true]
            );

            // Create course
            $course = Course::create([
                'title' => $data['title'],
                'description' => $data['description'],
                'instructor_id' => $instructorId,
                'category_id' => $category->id,
                'difficulty_level' => $data['difficulty_level'],
                'estimated_duration' => $data['estimated_duration'],
                'target_audience' => $data['target_audience'] ?? [],
                'learning_objectives' => $data['learning_objectives'] ?? [],
                'prerequisites' => $data['prerequisites'] ?? [],
                'price' => $data['price'] ?? 0,
                'currency' => $data['currency'] ?? 'TRY',
                'status' => $options['status'] ?? 'draft',
                'metadata' => array_merge($data['metadata'] ?? [], [
                    'imported_at' => now()->toISOString(),
                    'import_source' => $options['import_source'] ?? 'unknown'
                ])
            ]);

            // Create sections and lessons
            if (isset($data['sections']) && is_array($data['sections'])) {
                foreach ($data['sections'] as $sectionData) {
                    $section = CourseSection::create([
                        'course_id' => $course->id,
                        'title' => $sectionData['title'],
                        'description' => $sectionData['description'] ?? '',
                        'order' => $sectionData['order'] ?? 0,
                        'is_free' => $sectionData['is_free'] ?? false
                    ]);

                    if (isset($sectionData['lessons']) && is_array($sectionData['lessons'])) {
                        foreach ($sectionData['lessons'] as $lessonData) {
                            CourseLesson::create([
                                'course_id' => $course->id,
                                'section_id' => $section->id,
                                'title' => $lessonData['title'],
                                'description' => $lessonData['description'] ?? '',
                                'content_type' => $lessonData['content_type'],
                                'order' => $lessonData['order'] ?? 0,
                                'duration' => $lessonData['duration'] ?? 0,
                                'is_free' => $lessonData['is_free'] ?? false,
                                'content' => $lessonData['content'] ?? null,
                                'video_url' => $lessonData['video_url'] ?? null,
                                'attachments' => $lessonData['attachments'] ?? []
                            ]);
                        }
                    }
                }
            }

            DB::commit();
            return $course;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Export to JSON
     */
    private function exportToJSON($data, $filename)
    {
        $filename = $filename . '_' . now()->format('Y-m-d_H-i-s') . '.json';
        $filepath = storage_path('app/exports/' . $filename);

        file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => $filename,
            'format' => 'json'
        ];
    }

    /**
     * Export to CSV
     */
    private function exportToCSV($data, $filename)
    {
        $filename = $filename . '_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $filepath = storage_path('app/exports/' . $filename);

        $handle = fopen($filepath, 'w');

        if (is_array($data) && !empty($data)) {
            // Multiple courses
            $headers = array_keys($data[0]);
            fputcsv($handle, $headers);

            foreach ($data as $row) {
                fputcsv($handle, $row);
            }
        } else {
            // Single course
            $this->writeCourseToCSV($handle, $data);
        }

        fclose($handle);

        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => $filename,
            'format' => 'csv'
        ];
    }

    /**
     * Write course data to CSV
     */
    private function writeCourseToCSV($handle, $courseData)
    {
        // Write course header
        fputcsv($handle, ['Course Information']);
        fputcsv($handle, ['Title', $courseData['title']]);
        fputcsv($handle, ['Description', $courseData['description']]);
        fputcsv($handle, ['Category', $courseData['category']]);
        fputcsv($handle, ['Difficulty Level', $courseData['difficulty_level']]);
        fputcsv($handle, ['Estimated Duration', $courseData['estimated_duration']]);
        fputcsv($handle, ['Price', $courseData['price']]);
        fputcsv($handle, []);

        // Write sections
        if (!empty($courseData['sections'])) {
            fputcsv($handle, ['Sections']);
            fputcsv($handle, ['Section Title', 'Description', 'Order', 'Is Free']);

            foreach ($courseData['sections'] as $section) {
                fputcsv($handle, [
                    $section['title'],
                    $section['description'] ?? '',
                    $section['order'] ?? 0,
                    $section['is_free'] ? 'Yes' : 'No'
                ]);

                // Write lessons
                if (!empty($section['lessons'])) {
                    fputcsv($handle, ['', 'Lessons']);
                    fputcsv($handle, ['', '', 'Lesson Title', 'Description', 'Content Type', 'Order', 'Duration']);

                    foreach ($section['lessons'] as $lesson) {
                        fputcsv($handle, [
                            '', '', '',
                            $lesson['title'],
                            $lesson['description'] ?? '',
                            $lesson['content_type'],
                            $lesson['order'] ?? 0,
                            $lesson['duration'] ?? 0
                        ]);
                    }
                }
                fputcsv($handle, []);
            }
        }
    }

    /**
     * Export to XML
     */
    private function exportToXML($data, $filename)
    {
        $filename = $filename . '_' . now()->format('Y-m-d_H-i-s') . '.xml';
        $filepath = storage_path('app/exports/' . $filename);

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><courses></courses>');

        if (is_array($data) && !empty($data)) {
            // Multiple courses
            foreach ($data as $courseData) {
                $this->addCourseToXML($xml, $courseData);
            }
        } else {
            // Single course
            $this->addCourseToXML($xml, $data);
        }

        $xml->asXML($filepath);

        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => $filename,
            'format' => 'xml'
        ];
    }

    /**
     * Add course to XML
     */
    private function addCourseToXML($xml, $courseData)
    {
        $courseNode = $xml->addChild('course');
        $courseNode->addChild('title', $courseData['title']);
        $courseNode->addChild('description', $courseData['description']);
        $courseNode->addChild('category', $courseData['category']);
        $courseNode->addChild('difficulty_level', $courseData['difficulty_level']);
        $courseNode->addChild('estimated_duration', $courseData['estimated_duration']);
        $courseNode->addChild('price', $courseData['price']);

        if (!empty($courseData['sections'])) {
            $sectionsNode = $courseNode->addChild('sections');
            foreach ($courseData['sections'] as $section) {
                $sectionNode = $sectionsNode->addChild('section');
                $sectionNode->addChild('title', $section['title']);
                $sectionNode->addChild('description', $section['description'] ?? '');
                $sectionNode->addChild('order', $section['order'] ?? 0);
                $sectionNode->addChild('is_free', $section['is_free'] ? 'true' : 'false');

                if (!empty($section['lessons'])) {
                    $lessonsNode = $sectionNode->addChild('lessons');
                    foreach ($section['lessons'] as $lesson) {
                        $lessonNode = $lessonsNode->addChild('lesson');
                        $lessonNode->addChild('title', $lesson['title']);
                        $lessonNode->addChild('description', $lesson['description'] ?? '');
                        $lessonNode->addChild('content_type', $lesson['content_type']);
                        $lessonNode->addChild('order', $lesson['order'] ?? 0);
                        $lessonNode->addChild('duration', $lesson['duration'] ?? 0);
                    }
                }
            }
        }
    }

    /**
     * Export to Excel
     */
    private function exportToExcel($data, $filename)
    {
        // This would require a package like PhpSpreadsheet
        // For now, return CSV format
        return $this->exportToCSV($data, $filename);
    }

    /**
     * Get supported formats
     */
    public function getSupportedFormats()
    {
        return $this->supportedFormats;
    }

    /**
     * Get import template
     */
    public function getImportTemplate($format = 'json')
    {
        $template = [
            'title' => 'Sample Course Title',
            'description' => 'Sample course description',
            'category' => 'Technology',
            'difficulty_level' => 'beginner',
            'estimated_duration' => 120,
            'target_audience' => ['Students', 'Professionals'],
            'learning_objectives' => ['Learn basic concepts', 'Apply knowledge'],
            'prerequisites' => ['Basic computer skills'],
            'price' => 99.99,
            'currency' => 'TRY',
            'sections' => [
                [
                    'title' => 'Introduction',
                    'description' => 'Course introduction',
                    'order' => 1,
                    'is_free' => true,
                    'lessons' => [
                        [
                            'title' => 'Welcome',
                            'description' => 'Welcome to the course',
                            'content_type' => 'video',
                            'order' => 1,
                            'duration' => 10
                        ]
                    ]
                ]
            ]
        ];

        switch ($format) {
            case 'json':
                return response()->json($template);
            case 'csv':
                return $this->exportToCSV([$template], 'import_template');
            case 'xml':
                return $this->exportToXML($template, 'import_template');
            default:
                return response()->json($template);
        }
    }

    /**
     * Validate export format
     */
    public function validateExportFormat($format)
    {
        return in_array($format, $this->supportedFormats);
    }

    /**
     * Get import statistics
     */
    public function getImportStats($instructorId = null)
    {
        $query = Course::whereNotNull('metadata->imported_at');

        if ($instructorId) {
            $query->where('instructor_id', $instructorId);
        }

        $stats = [
            'total_imported' => $query->count(),
            'imports_by_month' => $query->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count')
                ->groupBy('month')
                ->orderBy('month', 'desc')
                ->pluck('count', 'month'),
            'imports_by_source' => $query->selectRaw('metadata->>"$.import_source" as source, COUNT(*) as count')
                ->groupBy('source')
                ->pluck('count', 'source'),
            'recent_imports' => $query->with('instructor')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
        ];

        return $stats;
    }
} 