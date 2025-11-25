<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category_id',
        'difficulty_level',
        'estimated_duration',
        'structure',
        'content_blocks',
        'assessment_types',
        'prerequisites',
        'learning_objectives',
        'is_public',
        'created_by',
        'usage_count',
        'rating',
        'tags'
    ];

    protected $casts = [
        'structure' => 'array',
        'content_blocks' => 'array',
        'assessment_types' => 'array',
        'prerequisites' => 'array',
        'learning_objectives' => 'array',
        'is_public' => 'boolean',
        'usage_count' => 'integer',
        'rating' => 'float',
        'tags' => 'array'
    ];

    /**
     * Get the category that owns the template
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the creator of the template
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get courses created from this template
     */
    public function courses()
    {
        return $this->hasMany(Course::class, 'template_id');
    }

    /**
     * Get template structure
     */
    public function getStructureAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    /**
     * Set template structure
     */
    public function setStructureAttribute($value)
    {
        $this->attributes['structure'] = json_encode($value);
    }

    /**
     * Get content blocks
     */
    public function getContentBlocksAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    /**
     * Set content blocks
     */
    public function setContentBlocksAttribute($value)
    {
        $this->attributes['content_blocks'] = json_encode($value);
    }

    /**
     * Get assessment types
     */
    public function getAssessmentTypesAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    /**
     * Set assessment types
     */
    public function setAssessmentTypesAttribute($value)
    {
        $this->attributes['assessment_types'] = json_encode($value);
    }

    /**
     * Get prerequisites
     */
    public function getPrerequisitesAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    /**
     * Set prerequisites
     */
    public function setPrerequisitesAttribute($value)
    {
        $this->attributes['prerequisites'] = json_encode($value);
    }

    /**
     * Get learning objectives
     */
    public function getLearningObjectivesAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    /**
     * Set learning objectives
     */
    public function setLearningObjectivesAttribute($value)
    {
        $this->attributes['learning_objectives'] = json_encode($value);
    }

    /**
     * Get tags
     */
    public function getTagsAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    /**
     * Set tags
     */
    public function setTagsAttribute($value)
    {
        $this->attributes['tags'] = json_encode($value);
    }

    /**
     * Create course from template
     */
    public function createCourse($data)
    {
        $course = new Course();
        $course->title = $data['title'] ?? $this->name;
        $course->description = $data['description'] ?? $this->description;
        $course->category_id = $data['category_id'] ?? $this->category_id;
        $course->difficulty_level = $data['difficulty_level'] ?? $this->difficulty_level;
        $course->estimated_duration = $data['estimated_duration'] ?? $this->estimated_duration;
        $course->template_id = $this->id;
        $course->instructor_id = auth()->id();
        $course->status = 'draft';
        
        $course->save();
        
        // Create course structure from template
        $this->createCourseStructure($course);
        
        // Create content blocks from template
        $this->createContentBlocks($course);
        
        // Update usage count
        $this->increment('usage_count');
        
        return $course;
    }

    /**
     * Create course structure from template
     */
    private function createCourseStructure($course)
    {
        foreach ($this->structure as $sectionData) {
            $section = $course->sections()->create([
                'title' => $sectionData['title'],
                'description' => $sectionData['description'] ?? '',
                'order' => $sectionData['order'] ?? 0,
                'is_free' => $sectionData['is_free'] ?? false
            ]);
            
            // Create lessons for this section
            if (isset($sectionData['lessons'])) {
                foreach ($sectionData['lessons'] as $lessonData) {
                    $lesson = $section->lessons()->create([
                        'title' => $lessonData['title'],
                        'description' => $lessonData['description'] ?? '',
                        'content' => $lessonData['content'] ?? '',
                        'type' => $lessonData['type'] ?? 'text',
                        'duration' => $lessonData['duration'] ?? 0,
                        'order' => $lessonData['order'] ?? 0,
                        'is_free' => $lessonData['is_free'] ?? false
                    ]);
                }
            }
        }
    }

    /**
     * Create content blocks from template
     */
    private function createContentBlocks($course)
    {
        foreach ($this->content_blocks as $blockData) {
            $course->contentBlocks()->create([
                'type' => $blockData['type'],
                'title' => $blockData['title'],
                'content' => $blockData['content'] ?? '',
                'order' => $blockData['order'] ?? 0,
                'is_active' => $blockData['is_active'] ?? true
            ]);
        }
    }

    /**
     * Get template rating
     */
    public function getRatingAttribute($value)
    {
        return round($value, 1);
    }

    /**
     * Update template rating
     */
    public function updateRating()
    {
        $courses = $this->courses()->where('rating', '>', 0);
        
        if ($courses->count() > 0) {
            $this->rating = $courses->avg('rating');
            $this->save();
        }
    }

    /**
     * Check if template is popular
     */
    public function isPopular()
    {
        return $this->usage_count >= 10;
    }

    /**
     * Get template difficulty label
     */
    public function getDifficultyLabelAttribute()
    {
        return match($this->difficulty_level) {
            'beginner' => 'Başlangıç',
            'intermediate' => 'Orta',
            'advanced' => 'İleri',
            'expert' => 'Uzman',
            default => 'Bilinmeyen'
        };
    }

    /**
     * Get estimated duration in hours
     */
    public function getDurationInHoursAttribute()
    {
        return round($this->estimated_duration / 60, 1);
    }

    /**
     * Get template preview
     */
    public function getPreviewAttribute()
    {
        return [
            'sections_count' => count($this->structure),
            'lessons_count' => collect($this->structure)->sum(function($section) {
                return count($section['lessons'] ?? []);
            }),
            'assessment_types' => $this->assessment_types,
            'prerequisites' => $this->prerequisites,
            'learning_objectives' => $this->learning_objectives
        ];
    }

    /**
     * Scope for public templates
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope for popular templates
     */
    public function scopePopular($query)
    {
        return $query->where('usage_count', '>=', 10);
    }

    /**
     * Scope for templates by category
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope for templates by difficulty
     */
    public function scopeByDifficulty($query, $difficulty)
    {
        return $query->where('difficulty_level', $difficulty);
    }

    /**
     * Search templates
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%")
              ->orWhereJsonContains('tags', $search);
        });
    }
} 