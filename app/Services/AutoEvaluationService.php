<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\AutoEvaluation;
use App\Models\AutoEvaluationCriterion;
use App\Models\User;
use App\Models\Course;
use App\Models\QuizAttempt;
use App\Models\AssignmentSubmission;

class AutoEvaluationService
{
    /**
     * Run automatic evaluation for a user
     */
    public function runAutomaticEvaluation(User $user, Course $course, string $evaluationType = 'overall'): AutoEvaluation
    {
        try {
            $evaluationData = $this->evaluateUserPerformance($user, $course, $evaluationType);
            
            $evaluation = AutoEvaluation::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'evaluation_type' => $evaluationType,
                'evaluation_date' => now(),
                'score' => $evaluationData['score'],
                'max_score' => $evaluationData['max_score'],
                'percentage' => $evaluationData['percentage'],
                'grade' => $evaluationData['grade'],
                'feedback' => $evaluationData['feedback'],
                'recommendations' => $evaluationData['recommendations'],
                'strengths' => $evaluationData['strengths'],
                'weaknesses' => $evaluationData['weaknesses'],
                'improvement_areas' => $evaluationData['improvement_areas'],
                'next_steps' => $evaluationData['next_steps'],
                'is_automated' => true,
                'metadata' => $evaluationData['metadata'] ?? []
            ]);

            // Create evaluation criteria
            foreach ($evaluationData['criteria'] ?? [] as $criterion) {
                $evaluation->criteria()->create([
                    'criterion_name' => $criterion['name'],
                    'criterion_score' => $criterion['score'],
                    'criterion_max_score' => $criterion['max_score'],
                    'criterion_weight' => $criterion['weight'],
                    'criterion_feedback' => $criterion['feedback']
                ]);
            }

            Log::info("Automatic evaluation completed successfully", [
                'user_id' => $user->id,
                'course_id' => $course->id,
                'evaluation_type' => $evaluationType,
                'grade' => $evaluationData['grade']
            ]);

            return $evaluation;

        } catch (\Exception $e) {
            Log::error("Failed to run automatic evaluation: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Evaluate user performance based on type
     */
    private function evaluateUserPerformance(User $user, Course $course, string $evaluationType): array
    {
        switch ($evaluationType) {
            case 'quiz':
                return $this->evaluateQuizPerformance($user, $course);
            case 'assignment':
                return $this->evaluateAssignmentPerformance($user, $course);
            case 'course_progress':
                return $this->evaluateCourseProgress($user, $course);
            case 'overall':
            default:
                return $this->evaluateOverallPerformance($user, $course);
        }
    }

    /**
     * Evaluate quiz performance
     */
    private function evaluateQuizPerformance(User $user, Course $course): array
    {
        $quizAttempts = $user->quizAttempts()
            ->whereHas('quiz', function($q) use ($course) {
                $q->where('course_id', $course->id);
            })
            ->get();

        if ($quizAttempts->isEmpty()) {
            return $this->getDefaultEvaluation('No quiz attempts found');
        }

        $totalScore = $quizAttempts->sum('score');
        $maxScore = $quizAttempts->sum('total_points');
        $percentage = $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0;
        $grade = $this->calculateGrade($percentage);

        $criteria = [
            [
                'name' => 'Quiz Performance',
                'score' => $totalScore,
                'max_score' => $maxScore,
                'weight' => 100,
                'feedback' => $this->getQuizFeedback($percentage)
            ]
        ];

        return [
            'score' => $totalScore,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'grade' => $grade,
            'feedback' => $this->getQuizFeedback($percentage),
            'recommendations' => $this->getQuizRecommendations($percentage),
            'strengths' => $this->getQuizStrengths($quizAttempts),
            'weaknesses' => $this->getQuizWeaknesses($quizAttempts),
            'improvement_areas' => $this->getQuizImprovementAreas($percentage),
            'next_steps' => $this->getQuizNextSteps($percentage),
            'criteria' => $criteria,
            'metadata' => [
                'total_attempts' => $quizAttempts->count(),
                'average_attempts_per_quiz' => $quizAttempts->count() / $quizAttempts->unique('quiz_id')->count()
            ]
        ];
    }

    /**
     * Evaluate assignment performance
     */
    private function evaluateAssignmentPerformance(User $user, Course $course): array
    {
        $assignments = $user->assignmentSubmissions()
            ->whereHas('assignment', function($q) use ($course) {
                $q->where('course_id', $course->id);
            })
            ->get();

        if ($assignments->isEmpty()) {
            return $this->getDefaultEvaluation('No assignment submissions found');
        }

        $totalScore = $assignments->sum('score');
        $maxScore = $assignments->sum('max_score');
        $percentage = $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0;
        $grade = $this->calculateGrade($percentage);

        $criteria = [
            [
                'name' => 'Assignment Performance',
                'score' => $totalScore,
                'max_score' => $maxScore,
                'weight' => 100,
                'feedback' => $this->getAssignmentFeedback($percentage)
            ]
        ];

        return [
            'score' => $totalScore,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'grade' => $grade,
            'feedback' => $this->getAssignmentFeedback($percentage),
            'recommendations' => $this->getAssignmentRecommendations($percentage),
            'strengths' => $this->getAssignmentStrengths($assignments),
            'weaknesses' => $this->getAssignmentWeaknesses($assignments),
            'improvement_areas' => $this->getAssignmentImprovementAreas($percentage),
            'next_steps' => $this->getAssignmentNextSteps($percentage),
            'criteria' => $criteria,
            'metadata' => [
                'total_submissions' => $assignments->count(),
                'late_submissions' => $assignments->where('is_late', true)->count()
            ]
        ];
    }

    /**
     * Evaluate course progress
     */
    private function evaluateCourseProgress(User $user, Course $course): array
    {
        $enrollment = $user->enrollments()->where('course_id', $course->id)->first();
        
        if (!$enrollment) {
            return $this->getDefaultEvaluation('User not enrolled in course');
        }

        $progressPercentage = $enrollment->progress_percentage ?? 0;
        $grade = $this->calculateGrade($progressPercentage);

        $criteria = [
            [
                'name' => 'Course Progress',
                'score' => $progressPercentage,
                'max_score' => 100,
                'weight' => 100,
                'feedback' => $this->getProgressFeedback($progressPercentage)
            ]
        ];

        return [
            'score' => $progressPercentage,
            'max_score' => 100,
            'percentage' => $progressPercentage,
            'grade' => $grade,
            'feedback' => $this->getProgressFeedback($progressPercentage),
            'recommendations' => $this->getProgressRecommendations($progressPercentage),
            'strengths' => $this->getProgressStrengths($progressPercentage),
            'weaknesses' => $this->getProgressWeaknesses($progressPercentage),
            'improvement_areas' => $this->getProgressImprovementAreas($progressPercentage),
            'next_steps' => $this->getProgressNextSteps($progressPercentage),
            'criteria' => $criteria,
            'metadata' => [
                'enrollment_date' => $enrollment->enrolled_at,
                'last_accessed' => $enrollment->last_accessed_at,
                'learning_hours' => $enrollment->learning_hours ?? 0
            ]
        ];
    }

    /**
     * Evaluate overall performance
     */
    private function evaluateOverallPerformance(User $user, Course $course): array
    {
        $quizEvaluation = $this->evaluateQuizPerformance($user, $course);
        $assignmentEvaluation = $this->evaluateAssignmentPerformance($user, $course);
        $progressEvaluation = $this->evaluateCourseProgress($user, $course);

        // Calculate weighted overall score
        $quizWeight = 0.4;
        $assignmentWeight = 0.4;
        $progressWeight = 0.2;

        $overallPercentage = round(
            ($quizEvaluation['percentage'] * $quizWeight) +
            ($assignmentEvaluation['percentage'] * $assignmentWeight) +
            ($progressEvaluation['percentage'] * $progressWeight),
            2
        );

        $grade = $this->calculateGrade($overallPercentage);

        $criteria = [
            [
                'name' => 'Quiz Performance',
                'score' => $quizEvaluation['percentage'],
                'max_score' => 100,
                'weight' => $quizWeight * 100,
                'feedback' => $quizEvaluation['feedback']
            ],
            [
                'name' => 'Assignment Performance',
                'score' => $assignmentEvaluation['percentage'],
                'max_score' => 100,
                'weight' => $assignmentWeight * 100,
                'feedback' => $assignmentEvaluation['feedback']
            ],
            [
                'name' => 'Course Progress',
                'score' => $progressEvaluation['percentage'],
                'max_score' => 100,
                'weight' => $progressWeight * 100,
                'feedback' => $progressEvaluation['feedback']
            ]
        ];

        return [
            'score' => $overallPercentage,
            'max_score' => 100,
            'percentage' => $overallPercentage,
            'grade' => $grade,
            'feedback' => $this->getOverallFeedback($overallPercentage),
            'recommendations' => $this->getOverallRecommendations($overallPercentage),
            'strengths' => array_merge(
                $quizEvaluation['strengths'] ?? [],
                $assignmentEvaluation['strengths'] ?? [],
                $progressEvaluation['strengths'] ?? []
            ),
            'weaknesses' => array_merge(
                $quizEvaluation['weaknesses'] ?? [],
                $assignmentEvaluation['weaknesses'] ?? [],
                $progressEvaluation['weaknesses'] ?? []
            ),
            'improvement_areas' => array_merge(
                $quizEvaluation['improvement_areas'] ?? [],
                $assignmentEvaluation['improvement_areas'] ?? [],
                $progressEvaluation['improvement_areas'] ?? []
            ),
            'next_steps' => $this->getOverallNextSteps($overallPercentage),
            'criteria' => $criteria,
            'metadata' => [
                'quiz_percentage' => $quizEvaluation['percentage'],
                'assignment_percentage' => $assignmentEvaluation['percentage'],
                'progress_percentage' => $progressEvaluation['percentage']
            ]
        ];
    }

    /**
     * Calculate grade based on percentage
     */
    private function calculateGrade(float $percentage): string
    {
        if ($percentage >= 90) return 'A';
        if ($percentage >= 80) return 'B';
        if ($percentage >= 70) return 'C';
        if ($percentage >= 60) return 'D';
        return 'F';
    }

    /**
     * Get default evaluation for edge cases
     */
    private function getDefaultEvaluation(string $message): array
    {
        return [
            'score' => 0,
            'max_score' => 100,
            'percentage' => 0,
            'grade' => 'F',
            'feedback' => $message,
            'recommendations' => ['Start participating in course activities'],
            'strengths' => [],
            'weaknesses' => ['No activity recorded'],
            'improvement_areas' => ['Course participation'],
            'next_steps' => ['Enroll in course activities'],
            'criteria' => [],
            'metadata' => []
        ];
    }

    /**
     * Get feedback methods for different evaluation types
     */
    private function getQuizFeedback(float $percentage): string
    {
        if ($percentage >= 90) return 'Excellent quiz performance! You have a strong understanding of the material.';
        if ($percentage >= 80) return 'Good quiz performance. You understand most concepts well.';
        if ($percentage >= 70) return 'Average quiz performance. Some areas need improvement.';
        if ($percentage >= 60) return 'Below average performance. Consider reviewing the material.';
        return 'Poor performance. Significant improvement needed.';
    }

    private function getAssignmentFeedback(float $percentage): string
    {
        if ($percentage >= 90) return 'Outstanding assignment work! Your submissions show excellent quality.';
        if ($percentage >= 80) return 'Good assignment work. Your submissions meet most requirements.';
        if ($percentage >= 70) return 'Satisfactory assignment work. Some improvements needed.';
        if ($percentage >= 60) return 'Below satisfactory work. More effort required.';
        return 'Unsatisfactory work. Major improvements needed.';
    }

    private function getProgressFeedback(float $percentage): string
    {
        if ($percentage >= 90) return 'Excellent course progress! You are ahead of schedule.';
        if ($percentage >= 80) return 'Good course progress. You are on track.';
        if ($percentage >= 70) return 'Satisfactory progress. Keep up the pace.';
        if ($percentage >= 60) return 'Below average progress. Consider increasing study time.';
        return 'Poor progress. Significant time investment needed.';
    }

    private function getOverallFeedback(float $percentage): string
    {
        if ($percentage >= 90) return 'Outstanding overall performance! You are excelling in this course.';
        if ($percentage >= 80) return 'Strong overall performance. You are doing well.';
        if ($percentage >= 70) return 'Good overall performance. You are meeting expectations.';
        if ($percentage >= 60) return 'Below average performance. Some areas need attention.';
        return 'Poor overall performance. Comprehensive improvement needed.';
    }

    /**
     * Get recommendation methods
     */
    private function getQuizRecommendations(float $percentage): array
    {
        if ($percentage >= 90) return ['Continue current study habits', 'Consider advanced topics'];
        if ($percentage >= 80) return ['Review missed questions', 'Focus on weak areas'];
        if ($percentage >= 70) return ['Increase study time', 'Review course materials'];
        if ($percentage >= 60) return ['Seek additional help', 'Review fundamentals'];
        return ['Seek tutoring', 'Review from beginning', 'Contact instructor'];
    }

    private function getAssignmentRecommendations(float $percentage): array
    {
        if ($percentage >= 90) return ['Maintain quality standards', 'Help peers if possible'];
        if ($percentage >= 80) return ['Review feedback carefully', 'Improve weak areas'];
        if ($percentage >= 70) return ['Plan assignments better', 'Seek clarification early'];
        if ($percentage >= 60) return ['Start assignments earlier', 'Review requirements'];
        return ['Seek help immediately', 'Review assignment guidelines', 'Contact instructor'];
    }

    private function getProgressRecommendations(float $percentage): array
    {
        if ($percentage >= 90) return ['Maintain current pace', 'Consider additional courses'];
        if ($percentage >= 80) return ['Stay on schedule', 'Plan ahead'];
        if ($percentage >= 70) return ['Increase study time', 'Set daily goals'];
        if ($percentage >= 60) return ['Create study schedule', 'Reduce distractions'];
        return ['Create strict schedule', 'Seek academic support', 'Consider course load'];
    }

    private function getOverallRecommendations(float $percentage): array
    {
        if ($percentage >= 90) return ['Maintain excellence', 'Consider advanced courses'];
        if ($percentage >= 80) return ['Continue good work', 'Focus on weak areas'];
        if ($percentage >= 70) return ['Improve study habits', 'Seek help when needed'];
        if ($percentage >= 60) return ['Increase effort', 'Review fundamentals'];
        return ['Comprehensive review needed', 'Seek academic support', 'Consider course load'];
    }

    /**
     * Get strengths, weaknesses, improvement areas, and next steps
     */
    private function getQuizStrengths($quizAttempts): array
    {
        $strengths = [];
        if ($quizAttempts->avg('percentage') >= 80) $strengths[] = 'Strong quiz performance';
        if ($quizAttempts->count() > 1) $strengths[] = 'Multiple attempts show persistence';
        return $strengths;
    }

    private function getQuizWeaknesses($quizAttempts): array
    {
        $weaknesses = [];
        if ($quizAttempts->avg('percentage') < 70) $weaknesses[] = 'Low quiz scores';
        if ($quizAttempts->count() > 3) $weaknesses[] = 'Too many attempts needed';
        return $weaknesses;
    }

    private function getQuizImprovementAreas(float $percentage): array
    {
        if ($percentage < 70) return ['Quiz preparation', 'Time management', 'Content understanding'];
        if ($percentage < 80) return ['Question analysis', 'Answer verification'];
        return ['Advanced topics', 'Speed improvement'];
    }

    private function getQuizNextSteps(float $percentage): array
    {
        if ($percentage < 70) return ['Review course materials', 'Practice with sample questions', 'Seek help'];
        if ($percentage < 80) return ['Focus on weak areas', 'Improve study techniques'];
        return ['Maintain performance', 'Challenge yourself'];
    }

    // Similar methods for assignments, progress, and overall...
    private function getAssignmentStrengths($assignments): array
    {
        $strengths = [];
        if ($assignments->avg('percentage') >= 80) $strengths[] = 'Strong assignment work';
        if ($assignments->where('is_late', false)->count() > 0) $strengths[] = 'Timely submissions';
        return $strengths;
    }

    private function getAssignmentWeaknesses($assignments): array
    {
        $weaknesses = [];
        if ($assignments->avg('percentage') < 70) $weaknesses[] = 'Low assignment scores';
        if ($assignments->where('is_late', true)->count() > 0) $weaknesses[] = 'Late submissions';
        return $weaknesses;
    }

    private function getAssignmentImprovementAreas(float $percentage): array
    {
        if ($percentage < 70) return ['Assignment planning', 'Quality improvement', 'Time management'];
        if ($percentage < 80) return ['Requirements understanding', 'Quality standards'];
        return ['Advanced techniques', 'Innovation'];
    }

    private function getAssignmentNextSteps(float $percentage): array
    {
        if ($percentage < 70) return ['Start early', 'Review requirements', 'Seek feedback'];
        if ($percentage < 80) return ['Improve quality', 'Plan better'];
        return ['Maintain standards', 'Innovate'];
    }

    private function getProgressStrengths(float $percentage): array
    {
        if ($percentage >= 80) return ['Good pace', 'Consistent progress'];
        if ($percentage >= 70) return ['Steady progress', 'On track'];
        return [];
    }

    private function getProgressWeaknesses(float $percentage): array
    {
        if ($percentage < 70) return ['Slow progress', 'Behind schedule'];
        if ($percentage < 80) return ['Moderate pace', 'Could improve'];
        return [];
    }

    private function getProgressImprovementAreas(float $percentage): array
    {
        if ($percentage < 70) return ['Study time', 'Focus', 'Organization'];
        if ($percentage < 80) return ['Efficiency', 'Planning'];
        return ['Advanced topics', 'Leadership'];
    }

    private function getProgressNextSteps(float $percentage): array
    {
        if ($percentage < 70) return ['Increase study time', 'Create schedule', 'Seek support'];
        if ($percentage < 80) return ['Improve efficiency', 'Better planning'];
        return ['Maintain pace', 'Help others'];
    }

    private function getOverallStrengths(float $percentage): array
    {
        if ($percentage >= 80) return ['Strong overall performance', 'Good balance'];
        if ($percentage >= 70) return ['Satisfactory performance', 'Some strengths'];
        return [];
    }

    private function getOverallWeaknesses(float $percentage): array
    {
        if ($percentage < 70) return ['Overall performance needs improvement', 'Multiple weak areas'];
        if ($percentage < 80) return ['Some areas need attention', 'Room for improvement'];
        return [];
    }

    private function getOverallImprovementAreas(float $percentage): array
    {
        if ($percentage < 70) return ['Comprehensive improvement needed', 'Study habits', 'Time management'];
        if ($percentage < 80) return ['Focus on weak areas', 'Improve techniques'];
        return ['Maintain excellence', 'Advanced skills'];
    }

    private function getOverallNextSteps(float $percentage): array
    {
        if ($percentage < 70) return ['Comprehensive review', 'Seek academic support', 'Improve habits'];
        if ($percentage < 80) return ['Target weak areas', 'Improve techniques'];
        return ['Maintain performance', 'Excel further'];
    }

    /**
     * Get evaluation statistics
     */
    public function getEvaluationStatistics(int $courseId): array
    {
        $totalEvaluations = AutoEvaluation::byCourse($courseId)->count();
        $automatedEvaluations = AutoEvaluation::byCourse($courseId)->automated()->count();
        $passingEvaluations = AutoEvaluation::byCourse($courseId)->passing()->count();
        $failingEvaluations = AutoEvaluation::byCourse($courseId)->failing()->count();
        
        $averageScore = AutoEvaluation::byCourse($courseId)->avg('percentage') ?? 0;
        $gradeDistribution = AutoEvaluation::byCourse($courseId)
            ->selectRaw('grade, COUNT(*) as count')
            ->groupBy('grade')
            ->pluck('count', 'grade')
            ->toArray();

        return [
            'total_evaluations' => $totalEvaluations,
            'automated_evaluations' => $automatedEvaluations,
            'passing_evaluations' => $passingEvaluations,
            'failing_evaluations' => $failingEvaluations,
            'average_score' => round($averageScore, 2),
            'passing_rate' => $totalEvaluations > 0 ? 
                round(($passingEvaluations / $totalEvaluations) * 100, 2) : 0,
            'grade_distribution' => $gradeDistribution
        ];
    }
}










