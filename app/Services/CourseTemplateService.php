<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\CourseTemplate;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\CourseLesson;

class CourseTemplateService
{
    protected $templateCategories = [
        'academic' => 'Academic',
        'professional' => 'Professional Development',
        'creative' => 'Creative Arts',
        'technology' => 'Technology',
        'business' => 'Business',
        'health' => 'Health & Wellness',
        'language' => 'Language Learning',
        'personal_development' => 'Personal Development'
    ];

    protected $difficultyLevels = [
        'beginner' => 'Beginner',
        'intermediate' => 'Intermediate',
        'advanced' => 'Advanced',
        'expert' => 'Expert'
    ];

    /**
     * Create course template
     */
    public function createTemplate($data)
    {
        try {
            $template = CourseTemplate::create([
                'name' => $data['name'],
                'description' => $data['description'],
                'category' => $data['category'],
                'difficulty_level' => $data['difficulty_level'],
                'estimated_duration' => $data['estimated_duration'],
                'target_audience' => $data['target_audience'],
                'learning_objectives' => $data['learning_objectives'] ?? [],
                'prerequisites' => $data['prerequisites'] ?? [],
                'course_structure' => $data['course_structure'] ?? [],
                'assessment_types' => $data['assessment_types'] ?? [],
                'resources' => $data['resources'] ?? [],
                'is_public' => $data['is_public'] ?? false,
                'created_by' => $data['created_by'],
                'rating' => 0,
                'usage_count' => 0,
                'metadata' => $data['metadata'] ?? []
            ]);

            Log::info("Course template created", [
                'template_id' => $template->id,
                'name' => $data['name'],
                'created_by' => $data['created_by']
            ]);

            return [
                'success' => true,
                'template' => $template,
                'message' => 'Course template created successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to create course template: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create course template'
            ];
        }
    }

    /**
     * Get all templates
     */
    public function getAllTemplates($filters = [])
    {
        $cacheKey = 'course_templates_' . md5(serialize($filters));
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $query = CourseTemplate::query();

        // Apply filters
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['difficulty_level'])) {
            $query->where('difficulty_level', $filters['difficulty_level']);
        }

        if (isset($filters['is_public'])) {
            $query->where('is_public', $filters['is_public']);
        }

        if (isset($filters['min_rating'])) {
            $query->where('rating', '>=', $filters['min_rating']);
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'LIKE', "%{$filters['search']}%")
                  ->orWhere('description', 'LIKE', "%{$filters['search']}%");
            });
        }

        $templates = $query->with('createdBy')
            ->orderBy('rating', 'desc')
            ->orderBy('usage_count', 'desc')
            ->paginate(20);

        Cache::put($cacheKey, $templates, 3600);

        return $templates;
    }

    /**
     * Get template by ID
     */
    public function getTemplate($templateId)
    {
        return CourseTemplate::with(['createdBy', 'courses'])->find($templateId);
    }

    /**
     * Update template
     */
    public function updateTemplate($templateId, $data)
    {
        try {
            $template = CourseTemplate::find($templateId);
            
            if (!$template) {
                return [
                    'success' => false,
                    'message' => 'Template not found'
                ];
            }

            // Update fields
            $updatableFields = [
                'name', 'description', 'category', 'difficulty_level',
                'estimated_duration', 'target_audience', 'learning_objectives',
                'prerequisites', 'course_structure', 'assessment_types',
                'resources', 'is_public', 'metadata'
            ];

            foreach ($updatableFields as $field) {
                if (isset($data[$field])) {
                    $template->$field = $data[$field];
                }
            }

            $template->updated_at = now();
            $template->save();

            // Clear cache
            Cache::forget('course_templates_' . md5(serialize([])));

            Log::info("Course template updated", [
                'template_id' => $templateId,
                'name' => $template->name
            ]);

            return [
                'success' => true,
                'template' => $template,
                'message' => 'Template updated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to update template: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update template'
            ];
        }
    }

    /**
     * Delete template
     */
    public function deleteTemplate($templateId)
    {
        try {
            $template = CourseTemplate::find($templateId);
            
            if (!$template) {
                return [
                    'success' => false,
                    'message' => 'Template not found'
                ];
            }

            // Check if template is being used
            if ($template->courses()->count() > 0) {
                return [
                    'success' => false,
                    'message' => 'Cannot delete template that is being used by courses'
                ];
            }

            $template->delete();

            // Clear cache
            Cache::forget('course_templates_' . md5(serialize([])));

            Log::info("Course template deleted", [
                'template_id' => $templateId,
                'name' => $template->name
            ]);

            return [
                'success' => true,
                'message' => 'Template deleted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to delete template: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete template'
            ];
        }
    }

    /**
     * Create course from template
     */
    public function createCourseFromTemplate($templateId, $instructorId, $courseData)
    {
        try {
            $template = CourseTemplate::find($templateId);
            
            if (!$template) {
                return [
                    'success' => false,
                    'message' => 'Template not found'
                ];
            }

            // Create course
            $course = Course::create([
                'title' => $courseData['title'],
                'description' => $courseData['description'] ?? $template->description,
                'instructor_id' => $instructorId,
                'category_id' => $courseData['category_id'],
                'difficulty_level' => $courseData['difficulty_level'] ?? $template->difficulty_level,
                'estimated_duration' => $courseData['estimated_duration'] ?? $template->estimated_duration,
                'target_audience' => $courseData['target_audience'] ?? $template->target_audience,
                'learning_objectives' => $courseData['learning_objectives'] ?? $template->learning_objectives,
                'prerequisites' => $courseData['prerequisites'] ?? $template->prerequisites,
                'status' => 'draft',
                'template_id' => $templateId,
                'metadata' => array_merge($template->metadata ?? [], $courseData['metadata'] ?? [])
            ]);

            // Create course structure from template
            $this->createCourseStructure($course, $template);

            // Update template usage count
            $template->increment('usage_count');

            Log::info("Course created from template", [
                'course_id' => $course->id,
                'template_id' => $templateId,
                'instructor_id' => $instructorId
            ]);

            return [
                'success' => true,
                'course' => $course,
                'message' => 'Course created from template successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to create course from template: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create course from template'
            ];
        }
    }

    /**
     * Create course structure from template
     */
    private function createCourseStructure($course, $template)
    {
        $courseStructure = $template->course_structure ?? [];

        foreach ($courseStructure as $sectionData) {
            // Create section
            $section = CourseSection::create([
                'course_id' => $course->id,
                'title' => $sectionData['title'],
                'description' => $sectionData['description'] ?? '',
                'order' => $sectionData['order'] ?? 0,
                'is_free' => $sectionData['is_free'] ?? false
            ]);

            // Create lessons in section
            if (isset($sectionData['lessons'])) {
                foreach ($sectionData['lessons'] as $lessonData) {
                    CourseLesson::create([
                        'course_id' => $course->id,
                        'section_id' => $section->id,
                        'title' => $lessonData['title'],
                        'description' => $lessonData['description'] ?? '',
                        'content_type' => $lessonData['content_type'] ?? 'video',
                        'order' => $lessonData['order'] ?? 0,
                        'duration' => $lessonData['duration'] ?? 0,
                        'is_free' => $lessonData['is_free'] ?? false
                    ]);
                }
            }
        }
    }

    /**
     * Rate template
     */
    public function rateTemplate($templateId, $userId, $rating, $comment = null)
    {
        try {
            $template = CourseTemplate::find($templateId);
            
            if (!$template) {
                return [
                    'success' => false,
                    'message' => 'Template not found'
                ];
            }

            // Check if user has already rated
            $existingRating = $template->ratings()->where('user_id', $userId)->first();
            
            if ($existingRating) {
                $existingRating->update([
                    'rating' => $rating,
                    'comment' => $comment,
                    'updated_at' => now()
                ]);
            } else {
                $template->ratings()->create([
                    'user_id' => $userId,
                    'rating' => $rating,
                    'comment' => $comment
                ]);
            }

            // Recalculate average rating
            $averageRating = $template->ratings()->avg('rating');
            $template->rating = round($averageRating, 2);
            $template->save();

            Log::info("Template rated", [
                'template_id' => $templateId,
                'user_id' => $userId,
                'rating' => $rating
            ]);

            return [
                'success' => true,
                'template' => $template,
                'message' => 'Template rated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to rate template: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to rate template'
            ];
        }
    }

    /**
     * Get template recommendations
     */
    public function getTemplateRecommendations($userId, $limit = 10)
    {
        $user = User::find($userId);
        
        if (!$user) {
            return collect([]);
        }

        // Get user preferences and history
        $userPreferences = $this->getUserPreferences($user);
        $userHistory = $this->getUserHistory($user);

        // Build recommendation query
        $query = CourseTemplate::where('is_public', true);

        // Filter by user preferences
        if (!empty($userPreferences['categories'])) {
            $query->whereIn('category', $userPreferences['categories']);
        }

        if (!empty($userPreferences['difficulty_levels'])) {
            $query->whereIn('difficulty_level', $userPreferences['difficulty_levels']);
        }

        // Exclude already used templates
        if (!empty($userHistory['used_templates'])) {
            $query->whereNotIn('id', $userHistory['used_templates']);
        }

        // Order by relevance score
        $templates = $query->with('createdBy')
            ->orderBy('rating', 'desc')
            ->orderBy('usage_count', 'desc')
            ->limit($limit)
            ->get();

        // Calculate relevance scores
        $templates = $templates->map(function ($template) use ($userPreferences, $userHistory) {
            $template->relevance_score = $this->calculateRelevanceScore($template, $userPreferences, $userHistory);
            return $template;
        });

        // Sort by relevance score
        return $templates->sortByDesc('relevance_score');
    }

    /**
     * Get user preferences
     */
    private function getUserPreferences($user)
    {
        // This would analyze user behavior and preferences
        // For now, return default preferences
        return [
            'categories' => ['technology', 'business', 'personal_development'],
            'difficulty_levels' => ['beginner', 'intermediate'],
            'preferred_duration' => 'medium'
        ];
    }

    /**
     * Get user history
     */
    private function getUserHistory($user)
    {
        // This would analyze user's course history
        // For now, return empty history
        return [
            'enrolled_courses' => [],
            'completed_courses' => [],
            'used_templates' => [],
            'preferred_instructors' => []
        ];
    }

    /**
     * Calculate relevance score
     */
    private function calculateRelevanceScore($template, $userPreferences, $userHistory)
    {
        $score = 0;

        // Category preference
        if (in_array($template->category, $userPreferences['categories'])) {
            $score += 30;
        }

        // Difficulty level preference
        if (in_array($template->difficulty_level, $userPreferences['difficulty_levels'])) {
            $score += 25;
        }

        // Rating score
        $score += ($template->rating / 10) * 25;

        // Popularity score
        $score += min(20, ($template->usage_count / 100) * 20);

        return $score;
    }

    /**
     * Get template statistics
     */
    public function getTemplateStats()
    {
        $stats = [
            'total_templates' => CourseTemplate::count(),
            'public_templates' => CourseTemplate::where('is_public', true)->count(),
            'private_templates' => CourseTemplate::where('is_public', false)->count(),
            'templates_by_category' => CourseTemplate::selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category'),
            'templates_by_difficulty' => CourseTemplate::selectRaw('difficulty_level, COUNT(*) as count')
                ->groupBy('difficulty_level')
                ->pluck('count', 'difficulty_level'),
            'average_rating' => round(CourseTemplate::avg('rating'), 2),
            'total_usage' => CourseTemplate::sum('usage_count'),
            'most_popular' => CourseTemplate::orderBy('usage_count', 'desc')->first(),
            'highest_rated' => CourseTemplate::orderBy('rating', 'desc')->first()
        ];

        return $stats;
    }

    /**
     * Export templates
     */
    public function exportTemplates($filters = [], $format = 'json')
    {
        $query = CourseTemplate::with('createdBy');

        // Apply filters
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['difficulty_level'])) {
            $query->where('difficulty_level', $filters['difficulty_level']);
        }

        if (isset($filters['is_public'])) {
            $query->where('is_public', $filters['is_public']);
        }

        $templates = $query->get();

        switch ($format) {
            case 'json':
                return $this->exportToJSON($templates);
            case 'csv':
                return $this->exportToCSV($templates);
            case 'xml':
                return $this->exportToXML($templates);
            default:
                return $this->exportToJSON($templates);
        }
    }

    /**
     * Export to JSON
     */
    private function exportToJSON($templates)
    {
        $data = $templates->map(function ($template) {
            return [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'category' => $template->category,
                'difficulty_level' => $template->difficulty_level,
                'estimated_duration' => $template->estimated_duration,
                'target_audience' => $template->target_audience,
                'learning_objectives' => $template->learning_objectives,
                'prerequisites' => $template->prerequisites,
                'course_structure' => $template->course_structure,
                'assessment_types' => $template->assessment_types,
                'resources' => $template->resources,
                'rating' => $template->rating,
                'usage_count' => $template->usage_count,
                'created_by' => $template->createdBy->name ?? 'Unknown',
                'created_at' => $template->created_at->format('Y-m-d H:i:s')
            ];
        });

        $filename = 'course_templates_' . now()->format('Y-m-d_H-i-s') . '.json';
        $filepath = storage_path('app/exports/' . $filename);

        file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => $filename
        ];
    }

    /**
     * Export to CSV
     */
    private function exportToCSV($templates)
    {
        $filename = 'course_templates_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $filepath = storage_path('app/exports/' . $filename);

        $handle = fopen($filepath, 'w');

        // Add headers
        fputcsv($handle, [
            'ID', 'Name', 'Description', 'Category', 'Difficulty Level',
            'Estimated Duration', 'Target Audience', 'Rating', 'Usage Count',
            'Created By', 'Created Date'
        ]);

        // Add data
        foreach ($templates as $template) {
            fputcsv($handle, [
                $template->id,
                $template->name,
                $template->description,
                $template->category,
                $template->difficulty_level,
                $template->estimated_duration,
                implode(', ', $template->target_audience ?? []),
                $template->rating,
                $template->usage_count,
                $template->createdBy->name ?? 'Unknown',
                $template->created_at->format('Y-m-d H:i:s')
            ]);
        }

        fclose($handle);

        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => $filename
        ];
    }

    /**
     * Export to XML
     */
    private function exportToXML($templates)
    {
        $filename = 'course_templates_' . now()->format('Y-m-d_H-i-s') . '.xml';
        $filepath = storage_path('app/exports/' . $filename);

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><templates></templates>');

        foreach ($templates as $template) {
            $templateNode = $xml->addChild('template');
            $templateNode->addChild('id', $template->id);
            $templateNode->addChild('name', $template->name);
            $templateNode->addChild('description', $template->description);
            $templateNode->addChild('category', $template->category);
            $templateNode->addChild('difficulty_level', $template->difficulty_level);
            $templateNode->addChild('estimated_duration', $template->estimated_duration);
            $templateNode->addChild('rating', $template->rating);
            $templateNode->addChild('usage_count', $template->usage_count);
            $templateNode->addChild('created_by', $template->createdBy->name ?? 'Unknown');
            $templateNode->addChild('created_at', $template->created_at->format('Y-m-d H:i:s'));
        }

        $xml->asXML($filepath);

        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => $filename
        ];
    }

    /**
     * Get template categories
     */
    public function getTemplateCategories()
    {
        return $this->templateCategories;
    }

    /**
     * Get difficulty levels
     */
    public function getDifficultyLevels()
    {
        return $this->difficultyLevels;
    }

    /**
     * Validate template data
     */
    public function validateTemplateData($data)
    {
        $errors = [];

        // Required fields
        $requiredFields = ['name', 'description', 'category', 'difficulty_level'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }

        // Validate category
        if (isset($data['category']) && !array_key_exists($data['category'], $this->templateCategories)) {
            $errors[] = "Invalid category selected";
        }

        // Validate difficulty level
        if (isset($data['difficulty_level']) && !array_key_exists($data['difficulty_level'], $this->difficultyLevels)) {
            $errors[] = "Invalid difficulty level selected";
        }

        // Validate estimated duration
        if (isset($data['estimated_duration']) && $data['estimated_duration'] <= 0) {
            $errors[] = "Estimated duration must be greater than 0";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
} 