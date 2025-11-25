<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\InstructorEvaluation;
use App\Models\User;
use App\Models\Course;

class InstructorEvaluationService
{
    protected $evaluationCriteria = [
        'teaching_quality' => ['weight' => 25, 'max_score' => 10],
        'course_content' => ['weight' => 20, 'max_score' => 10],
        'student_satisfaction' => ['weight' => 20, 'max_score' => 10],
        'communication' => ['weight' => 15, 'max_score' => 10],
        'responsiveness' => ['weight' => 10, 'max_score' => 10],
        'professionalism' => ['weight' => 10, 'max_score' => 10]
    ];

    /**
     * Create instructor evaluation
     */
    public function createEvaluation($data)
    {
        try {
            $evaluation = InstructorEvaluation::create([
                'instructor_id' => $data['instructor_id'],
                'evaluator_id' => $data['evaluator_id'],
                'evaluation_type' => $data['evaluation_type'],
                'teaching_quality' => $data['teaching_quality'] ?? 0,
                'course_content' => $data['course_content'] ?? 0,
                'student_satisfaction' => $data['student_satisfaction'] ?? 0,
                'communication' => $data['communication'] ?? 0,
                'responsiveness' => $data['responsiveness'] ?? 0,
                'professionalism' => $data['professionalism'] ?? 0,
                'overall_score' => $this->calculateOverallScore($data),
                'comments' => $data['comments'] ?? '',
                'evaluation_date' => now(),
                'status' => 'active'
            ]);

            // Clear cache
            $this->clearEvaluationCache($data['instructor_id']);

            Log::info("Instructor evaluation created", [
                'evaluation_id' => $evaluation->id,
                'instructor_id' => $data['instructor_id'],
                'evaluator_id' => $data['evaluator_id']
            ]);

            return [
                'success' => true,
                'evaluation' => $evaluation,
                'message' => 'Evaluation created successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to create evaluation: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create evaluation'
            ];
        }
    }

    /**
     * Calculate overall score
     */
    private function calculateOverallScore($data)
    {
        $totalScore = 0;
        $totalWeight = 0;

        foreach ($this->evaluationCriteria as $criterion => $config) {
            if (isset($data[$criterion])) {
                $score = min($data[$criterion], $config['max_score']);
                $totalScore += ($score / $config['max_score']) * $config['weight'];
                $totalWeight += $config['weight'];
            }
        }

        return $totalWeight > 0 ? round(($totalScore / $totalWeight) * 10, 2) : 0;
    }

    /**
     * Get instructor evaluations
     */
    public function getInstructorEvaluations($instructorId, $filters = [])
    {
        $cacheKey = "instructor_evaluations_{$instructorId}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $query = InstructorEvaluation::with(['instructor', 'evaluator'])
            ->where('instructor_id', $instructorId)
            ->where('status', 'active');

        // Apply filters
        if (isset($filters['evaluation_type'])) {
            $query->where('evaluation_type', $filters['evaluation_type']);
        }

        if (isset($filters['date_from'])) {
            $query->where('evaluation_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('evaluation_date', '<=', $filters['date_to']);
        }

        if (isset($filters['min_score'])) {
            $query->where('overall_score', '>=', $filters['min_score']);
        }

        $evaluations = $query->orderBy('evaluation_date', 'desc')->paginate(20);

        Cache::put($cacheKey, $evaluations, 3600);

        return $evaluations;
    }

    /**
     * Get evaluation by ID
     */
    public function getEvaluation($evaluationId)
    {
        return InstructorEvaluation::with(['instructor', 'evaluator'])->find($evaluationId);
    }

    /**
     * Update evaluation
     */
    public function updateEvaluation($evaluationId, $data)
    {
        try {
            $evaluation = InstructorEvaluation::find($evaluationId);
            
            if (!$evaluation) {
                return [
                    'success' => false,
                    'message' => 'Evaluation not found'
                ];
            }

            // Update scores
            foreach ($this->evaluationCriteria as $criterion => $config) {
                if (isset($data[$criterion])) {
                    $evaluation->$criterion = $data[$criterion];
                }
            }

            // Update other fields
            if (isset($data['comments'])) {
                $evaluation->comments = $data['comments'];
            }

            if (isset($data['evaluation_type'])) {
                $evaluation->evaluation_type = $data['evaluation_type'];
            }

            // Recalculate overall score
            $evaluation->overall_score = $this->calculateOverallScore($data);
            $evaluation->updated_at = now();

            $evaluation->save();

            // Clear cache
            $this->clearEvaluationCache($evaluation->instructor_id);

            Log::info("Evaluation updated", [
                'evaluation_id' => $evaluationId,
                'instructor_id' => $evaluation->instructor_id
            ]);

            return [
                'success' => true,
                'evaluation' => $evaluation,
                'message' => 'Evaluation updated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to update evaluation: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update evaluation'
            ];
        }
    }

    /**
     * Delete evaluation
     */
    public function deleteEvaluation($evaluationId)
    {
        try {
            $evaluation = InstructorEvaluation::find($evaluationId);
            
            if (!$evaluation) {
                return [
                    'success' => false,
                    'message' => 'Evaluation not found'
                ];
            }

            $instructorId = $evaluation->instructor_id;
            $evaluation->delete();

            // Clear cache
            $this->clearEvaluationCache($instructorId);

            Log::info("Evaluation deleted", [
                'evaluation_id' => $evaluationId,
                'instructor_id' => $instructorId
            ]);

            return [
                'success' => true,
                'message' => 'Evaluation deleted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to delete evaluation: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete evaluation'
            ];
        }
    }

    /**
     * Get instructor performance summary
     */
    public function getInstructorPerformance($instructorId)
    {
        $cacheKey = "instructor_performance_{$instructorId}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $evaluations = InstructorEvaluation::where('instructor_id', $instructorId)
            ->where('status', 'active')
            ->get();

        if ($evaluations->isEmpty()) {
            return [
                'total_evaluations' => 0,
                'average_score' => 0,
                'performance_rating' => 'No Data',
                'criterion_scores' => [],
                'trend' => 'stable'
            ];
        }

        $performance = [
            'total_evaluations' => $evaluations->count(),
            'average_score' => round($evaluations->avg('overall_score'), 2),
            'performance_rating' => $this->getPerformanceRating($evaluations->avg('overall_score')),
            'criterion_scores' => $this->getCriterionScores($evaluations),
            'trend' => $this->getPerformanceTrend($evaluations),
            'recent_evaluations' => $evaluations->take(5)->map(function ($eval) {
                return [
                    'date' => $eval->evaluation_date->format('Y-m-d'),
                    'score' => $eval->overall_score,
                    'type' => $eval->evaluation_type
                ];
            })
        ];

        Cache::put($cacheKey, $performance, 3600);

        return $performance;
    }

    /**
     * Get performance rating
     */
    private function getPerformanceRating($score)
    {
        if ($score >= 9.0) return 'Excellent';
        if ($score >= 8.0) return 'Very Good';
        if ($score >= 7.0) return 'Good';
        if ($score >= 6.0) return 'Satisfactory';
        if ($score >= 5.0) return 'Needs Improvement';
        return 'Poor';
    }

    /**
     * Get criterion scores
     */
    private function getCriterionScores($evaluations)
    {
        $criterionScores = [];
        
        foreach ($this->evaluationCriteria as $criterion => $config) {
            $criterionScores[$criterion] = [
                'average' => round($evaluations->avg($criterion), 2),
                'max_score' => $config['max_score'],
                'weight' => $config['weight']
            ];
        }

        return $criterionScores;
    }

    /**
     * Get performance trend
     */
    private function getPerformanceTrend($evaluations)
    {
        if ($evaluations->count() < 2) {
            return 'stable';
        }

        $recent = $evaluations->take(3)->avg('overall_score');
        $older = $evaluations->slice(3, 3)->avg('overall_score');

        if ($recent > $older + 0.5) return 'improving';
        if ($recent < $older - 0.5) return 'declining';
        return 'stable';
    }

    /**
     * Get evaluation statistics
     */
    public function getEvaluationStats($filters = [])
    {
        $query = InstructorEvaluation::with('instructor');

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->where('evaluation_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('evaluation_date', '<=', $filters['date_to']);
        }

        if (isset($filters['evaluation_type'])) {
            $query->where('evaluation_type', $filters['evaluation_type']);
        }

        $evaluations = $query->get();

        $stats = [
            'total_evaluations' => $evaluations->count(),
            'average_score' => round($evaluations->avg('overall_score'), 2),
            'score_distribution' => [
                'excellent' => $evaluations->where('overall_score', '>=', 9.0)->count(),
                'very_good' => $evaluations->whereBetween('overall_score', [8.0, 8.99])->count(),
                'good' => $evaluations->whereBetween('overall_score', [7.0, 7.99])->count(),
                'satisfactory' => $evaluations->whereBetween('overall_score', [6.0, 6.99])->count(),
                'needs_improvement' => $evaluations->whereBetween('overall_score', [5.0, 5.99])->count(),
                'poor' => $evaluations->where('overall_score', '<', 5.0)->count()
            ],
            'evaluations_by_type' => $evaluations->groupBy('evaluation_type')
                ->map(function ($group) {
                    return [
                        'count' => $group->count(),
                        'average_score' => round($group->avg('overall_score'), 2)
                    ];
                }),
            'top_instructors' => $evaluations->groupBy('instructor_id')
                ->map(function ($group) {
                    return [
                        'instructor_id' => $group->first()->instructor_id,
                        'instructor_name' => $group->first()->instructor->name ?? 'Unknown',
                        'average_score' => round($group->avg('overall_score'), 2),
                        'evaluation_count' => $group->count()
                    ];
                })
                ->sortByDesc('average_score')
                ->take(10)
                ->values()
        ];

        return $stats;
    }

    /**
     * Generate evaluation report
     */
    public function generateEvaluationReport($instructorId, $format = 'pdf')
    {
        $performance = $this->getInstructorPerformance($instructorId);
        $evaluations = $this->getInstructorEvaluations($instructorId, ['per_page' => 1000]);
        $instructor = User::find($instructorId);

        $reportData = [
            'instructor' => $instructor,
            'performance' => $performance,
            'evaluations' => $evaluations,
            'generated_at' => now(),
            'report_period' => 'All Time'
        ];

        switch ($format) {
            case 'pdf':
                return $this->generatePDFReport($reportData);
            case 'excel':
                return $this->generateExcelReport($reportData);
            case 'json':
                return $this->generateJSONReport($reportData);
            default:
                return $this->generatePDFReport($reportData);
        }
    }

    /**
     * Generate PDF report
     */
    private function generatePDFReport($data)
    {
        // This would require a PDF library like Dompdf
        // For now, return JSON with PDF generation instructions
        return [
            'success' => true,
            'message' => 'PDF report generation requires additional setup',
            'data' => $data
        ];
    }

    /**
     * Generate Excel report
     */
    private function generateExcelReport($data)
    {
        // This would require a package like PhpSpreadsheet
        // For now, return JSON with Excel generation instructions
        return [
            'success' => true,
            'message' => 'Excel report generation requires additional setup',
            'data' => $data
        ];
    }

    /**
     * Generate JSON report
     */
    private function generateJSONReport($data)
    {
        $filename = 'evaluation_report_' . $data['instructor']->id . '_' . now()->format('Y-m-d_H-i-s') . '.json';
        $filepath = storage_path('app/reports/' . $filename);

        file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => $filename
        ];
    }

    /**
     * Clear evaluation cache
     */
    private function clearEvaluationCache($instructorId)
    {
        Cache::forget("instructor_evaluations_{$instructorId}");
        Cache::forget("instructor_performance_{$instructorId}");
    }

    /**
     * Get evaluation types
     */
    public function getEvaluationTypes()
    {
        return [
            'student' => 'Student Evaluation',
            'peer' => 'Peer Evaluation',
            'admin' => 'Administrative Evaluation',
            'self' => 'Self Evaluation',
            'external' => 'External Evaluation'
        ];
    }

    /**
     * Check evaluation eligibility
     */
    public function canEvaluate($evaluatorId, $instructorId, $evaluationType)
    {
        // Students can only evaluate instructors of courses they're enrolled in
        if ($evaluationType === 'student') {
            $enrolledCourses = Course::whereHas('enrollments', function ($query) use ($evaluatorId) {
                $query->where('user_id', $evaluatorId);
            })->where('instructor_id', $instructorId)->exists();

            return $enrolledCourses;
        }

        // Admins can evaluate any instructor
        if ($evaluationType === 'admin') {
            $user = User::find($evaluatorId);
            return $user && $user->role === 'admin';
        }

        // Instructors can evaluate other instructors
        if ($evaluationType === 'peer') {
            $user = User::find($evaluatorId);
            return $user && $user->role === 'instructor' && $evaluatorId !== $instructorId;
        }

        // Self evaluation is always allowed
        if ($evaluationType === 'self') {
            return $evaluatorId === $instructorId;
        }

        return false;
    }

    /**
     * Get evaluation frequency
     */
    public function getEvaluationFrequency($instructorId)
    {
        $evaluations = InstructorEvaluation::where('instructor_id', $instructorId)
            ->where('status', 'active')
            ->orderBy('evaluation_date', 'desc')
            ->get();

        if ($evaluations->count() < 2) {
            return 'Insufficient data';
        }

        $totalDays = $evaluations->first()->evaluation_date->diffInDays($evaluations->last()->evaluation_date);
        $frequency = $totalDays / ($evaluations->count() - 1);

        if ($frequency <= 7) return 'Weekly';
        if ($frequency <= 30) return 'Monthly';
        if ($frequency <= 90) return 'Quarterly';
        return 'Annually';
    }
} 