<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\InstructorCertification;
use App\Models\User;
use App\Models\Course;

class InstructorCertificationService
{
    protected $certificationTypes = [
        'teaching' => 'Teaching Certification',
        'subject_matter' => 'Subject Matter Expert',
        'online_education' => 'Online Education Specialist',
        'assessment' => 'Assessment & Evaluation',
        'technology' => 'Educational Technology',
        'leadership' => 'Educational Leadership'
    ];

    protected $certificationLevels = [
        'beginner' => 'Beginner',
        'intermediate' => 'Intermediate',
        'advanced' => 'Advanced',
        'expert' => 'Expert'
    ];

    /**
     * Create instructor certification
     */
    public function createCertification($data)
    {
        try {
            $certification = InstructorCertification::create([
                'instructor_id' => $data['instructor_id'],
                'certification_type' => $data['certification_type'],
                'certification_level' => $data['certification_level'],
                'issuing_organization' => $data['issuing_organization'],
                'certificate_number' => $this->generateCertificateNumber(),
                'issue_date' => $data['issue_date'],
                'expiry_date' => $data['expiry_date'] ?? null,
                'credits_earned' => $data['credits_earned'] ?? 0,
                'status' => 'active',
                'verification_status' => 'pending',
                'certificate_file' => $data['certificate_file'] ?? null,
                'description' => $data['description'] ?? '',
                'metadata' => $data['metadata'] ?? []
            ]);

            // Upload certificate file if provided
            if (isset($data['certificate_file']) && $data['certificate_file']) {
                $filePath = $this->uploadCertificateFile($data['certificate_file'], $certification->id);
                $certification->certificate_file = $filePath;
                $certification->save();
            }

            // Clear cache
            $this->clearCertificationCache($data['instructor_id']);

            Log::info("Instructor certification created", [
                'certification_id' => $certification->id,
                'instructor_id' => $data['instructor_id'],
                'type' => $data['certification_type']
            ]);

            return [
                'success' => true,
                'certification' => $certification,
                'message' => 'Certification created successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to create certification: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create certification'
            ];
        }
    }

    /**
     * Generate unique certificate number
     */
    private function generateCertificateNumber()
    {
        do {
            $number = 'CERT-' . date('Y') . '-' . Str::random(8);
        } while (InstructorCertification::where('certificate_number', $number)->exists());

        return $number;
    }

    /**
     * Upload certificate file
     */
    private function uploadCertificateFile($file, $certificationId)
    {
        $filename = 'certificate_' . $certificationId . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = "certificates/{$certificationId}";
        
        $filePath = Storage::disk('public')->putFileAs($path, $file, $filename);
        
        return $filePath;
    }

    /**
     * Get instructor certifications
     */
    public function getInstructorCertifications($instructorId, $filters = [])
    {
        $cacheKey = "instructor_certifications_{$instructorId}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $query = InstructorCertification::where('instructor_id', $instructorId);

        // Apply filters
        if (isset($filters['certification_type'])) {
            $query->where('certification_type', $filters['certification_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['verification_status'])) {
            $query->where('verification_status', $filters['verification_status']);
        }

        if (isset($filters['expired'])) {
            if ($filters['expired']) {
                $query->where('expiry_date', '<', now());
            } else {
                $query->where(function ($q) {
                    $q->where('expiry_date', '>=', now())
                      ->orWhereNull('expiry_date');
                });
            }
        }

        $certifications = $query->orderBy('issue_date', 'desc')->get();

        Cache::put($cacheKey, $certifications, 3600);

        return $certifications;
    }

    /**
     * Get certification by ID
     */
    public function getCertification($certificationId)
    {
        return InstructorCertification::with('instructor')->find($certificationId);
    }

    /**
     * Update certification
     */
    public function updateCertification($certificationId, $data)
    {
        try {
            $certification = InstructorCertification::find($certificationId);
            
            if (!$certification) {
                return [
                    'success' => false,
                    'message' => 'Certification not found'
                ];
            }

            // Update fields
            $updatableFields = [
                'certification_type', 'certification_level', 'issuing_organization',
                'issue_date', 'expiry_date', 'credits_earned', 'description', 'metadata'
            ];

            foreach ($updatableFields as $field) {
                if (isset($data[$field])) {
                    $certification->$field = $data[$field];
                }
            }

            // Update certificate file if provided
            if (isset($data['certificate_file']) && $data['certificate_file']) {
                // Delete old file
                if ($certification->certificate_file) {
                    Storage::disk('public')->delete($certification->certificate_file);
                }
                
                $filePath = $this->uploadCertificateFile($data['certificate_file'], $certification->id);
                $certification->certificate_file = $filePath;
            }

            $certification->updated_at = now();
            $certification->save();

            // Clear cache
            $this->clearCertificationCache($certification->instructor_id);

            Log::info("Certification updated", [
                'certification_id' => $certificationId,
                'instructor_id' => $certification->instructor_id
            ]);

            return [
                'success' => true,
                'certification' => $certification,
                'message' => 'Certification updated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to update certification: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update certification'
            ];
        }
    }

    /**
     * Delete certification
     */
    public function deleteCertification($certificationId)
    {
        try {
            $certification = InstructorCertification::find($certificationId);
            
            if (!$certification) {
                return [
                    'success' => false,
                    'message' => 'Certification not found'
                ];
            }

            // Delete certificate file
            if ($certification->certificate_file) {
                Storage::disk('public')->delete($certification->certificate_file);
            }

            $instructorId = $certification->instructor_id;
            $certification->delete();

            // Clear cache
            $this->clearCertificationCache($instructorId);

            Log::info("Certification deleted", [
                'certification_id' => $certificationId,
                'instructor_id' => $instructorId
            ]);

            return [
                'success' => true,
                'message' => 'Certification deleted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to delete certification: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete certification'
            ];
        }
    }

    /**
     * Verify certification
     */
    public function verifyCertification($certificationId, $adminId, $verificationData)
    {
        try {
            $certification = InstructorCertification::find($certificationId);
            
            if (!$certification) {
                return [
                    'success' => false,
                    'message' => 'Certification not found'
                ];
            }

            $certification->verification_status = $verificationData['status'];
            $certification->verified_by = $adminId;
            $certification->verified_at = now();
            $certification->verification_notes = $verificationData['notes'] ?? null;
            $certification->verification_evidence = $verificationData['evidence'] ?? null;

            $certification->save();

            // Clear cache
            $this->clearCertificationCache($certification->instructor_id);

            Log::info("Certification verified", [
                'certification_id' => $certificationId,
                'admin_id' => $adminId,
                'status' => $verificationData['status']
            ]);

            return [
                'success' => true,
                'certification' => $certification,
                'message' => 'Certification verification updated'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to verify certification: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to verify certification'
            ];
        }
    }

    /**
     * Get certification summary
     */
    public function getCertificationSummary($instructorId)
    {
        $cacheKey = "certification_summary_{$instructorId}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $certifications = InstructorCertification::where('instructor_id', $instructorId)
            ->where('status', 'active')
            ->get();

        $summary = [
            'total_certifications' => $certifications->count(),
            'verified_certifications' => $certifications->where('verification_status', 'verified')->count(),
            'pending_verification' => $certifications->where('verification_status', 'pending')->count(),
            'total_credits' => $certifications->sum('credits_earned'),
            'certifications_by_type' => $certifications->groupBy('certification_type')
                ->map(function ($group) {
                    return [
                        'count' => $group->count(),
                        'verified' => $group->where('verification_status', 'verified')->count(),
                        'credits' => $group->sum('credits_earned')
                    ];
                }),
            'certifications_by_level' => $certifications->groupBy('certification_level')
                ->map(function ($group) {
                    return [
                        'count' => $group->count(),
                        'verified' => $group->where('verification_status', 'verified')->count()
                    ];
                }),
            'expiring_soon' => $certifications->where('expiry_date', '>=', now())
                ->where('expiry_date', '<=', now()->addMonths(3))
                ->count(),
            'expired' => $certifications->where('expiry_date', '<', now())->count()
        ];

        Cache::put($cacheKey, $summary, 3600);

        return $summary;
    }

    /**
     * Check certification requirements
     */
    public function checkCertificationRequirements($instructorId, $certificationType)
    {
        $requirements = $this->getCertificationRequirements($certificationType);
        $instructor = User::find($instructorId);
        
        if (!$instructor) {
            return [
                'eligible' => false,
                'message' => 'Instructor not found'
            ];
        }

        $results = [
            'eligible' => true,
            'requirements' => [],
            'missing_requirements' => [],
            'overall_score' => 0
        ];

        foreach ($requirements as $requirement) {
            $requirementResult = $this->checkRequirement($instructor, $requirement);
            $results['requirements'][] = $requirementResult;
            
            if (!$requirementResult['met']) {
                $results['eligible'] = false;
                $results['missing_requirements'][] = $requirement['name'];
            }
            
            $results['overall_score'] += $requirementResult['score'];
        }

        $results['overall_score'] = round($results['overall_score'] / count($requirements), 2);

        return $results;
    }

    /**
     * Get certification requirements
     */
    private function getCertificationRequirements($certificationType)
    {
        $requirements = [
            'teaching' => [
                [
                    'name' => 'Minimum Teaching Hours',
                    'type' => 'teaching_hours',
                    'required' => 100,
                    'weight' => 25
                ],
                [
                    'name' => 'Student Satisfaction Score',
                    'type' => 'student_satisfaction',
                    'required' => 8.0,
                    'weight' => 30
                ],
                [
                    'name' => 'Course Completion Rate',
                    'type' => 'completion_rate',
                    'required' => 85,
                    'weight' => 25
                ],
                [
                    'name' => 'Assessment Quality',
                    'type' => 'assessment_quality',
                    'required' => 8.5,
                    'weight' => 20
                ]
            ],
            'subject_matter' => [
                [
                    'name' => 'Educational Background',
                    'type' => 'education',
                    'required' => 'bachelor',
                    'weight' => 40
                ],
                [
                    'name' => 'Industry Experience',
                    'type' => 'experience',
                    'required' => 3,
                    'weight' => 30
                ],
                [
                    'name' => 'Subject Knowledge Test',
                    'type' => 'knowledge_test',
                    'required' => 90,
                    'weight' => 30
                ]
            ]
        ];

        return $requirements[$certificationType] ?? [];
    }

    /**
     * Check individual requirement
     */
    private function checkRequirement($instructor, $requirement)
    {
        $result = [
            'name' => $requirement['name'],
            'type' => $requirement['type'],
            'required' => $requirement['required'],
            'actual' => 0,
            'met' => false,
            'score' => 0,
            'weight' => $requirement['weight']
        ];

        switch ($requirement['type']) {
            case 'teaching_hours':
                $result['actual'] = $this->getTeachingHours($instructor->id);
                $result['met'] = $result['actual'] >= $requirement['required'];
                $result['score'] = min(100, ($result['actual'] / $requirement['required']) * 100);
                break;

            case 'student_satisfaction':
                $result['actual'] = $this->getStudentSatisfaction($instructor->id);
                $result['met'] = $result['actual'] >= $requirement['required'];
                $result['score'] = min(100, ($result['actual'] / $requirement['required']) * 100);
                break;

            case 'completion_rate':
                $result['actual'] = $this->getCompletionRate($instructor->id);
                $result['met'] = $result['actual'] >= $requirement['required'];
                $result['score'] = min(100, ($result['actual'] / $requirement['required']) * 100);
                break;

            case 'assessment_quality':
                $result['actual'] = $this->getAssessmentQuality($instructor->id);
                $result['met'] = $result['actual'] >= $requirement['required'];
                $result['score'] = min(100, ($result['actual'] / $requirement['required']) * 100);
                break;

            case 'education':
                $result['actual'] = $this->getEducationLevel($instructor->id);
                $result['met'] = $this->compareEducationLevel($result['actual'], $requirement['required']);
                $result['score'] = $result['met'] ? 100 : 50;
                break;

            case 'experience':
                $result['actual'] = $this->getIndustryExperience($instructor->id);
                $result['met'] = $result['actual'] >= $requirement['required'];
                $result['score'] = min(100, ($result['actual'] / $requirement['required']) * 100);
                break;

            case 'knowledge_test':
                $result['actual'] = $this->getKnowledgeTestScore($instructor->id);
                $result['met'] = $result['actual'] >= $requirement['required'];
                $result['score'] = $result['actual'];
                break;
        }

        return $result;
    }

    /**
     * Get teaching hours
     */
    private function getTeachingHours($instructorId)
    {
        // This would calculate actual teaching hours from course data
        // For now, return a placeholder value
        return rand(50, 200);
    }

    /**
     * Get student satisfaction
     */
    private function getStudentSatisfaction($instructorId)
    {
        // This would calculate actual student satisfaction score
        // For now, return a placeholder value
        return rand(7, 10);
    }

    /**
     * Get completion rate
     */
    private function getCompletionRate($instructorId)
    {
        // This would calculate actual completion rate
        // For now, return a placeholder value
        return rand(70, 95);
    }

    /**
     * Get assessment quality
     */
    private function getAssessmentQuality($instructorId)
    {
        // This would calculate actual assessment quality score
        // For now, return a placeholder value
        return rand(7, 10);
    }

    /**
     * Get education level
     */
    private function getEducationLevel($instructorId)
    {
        // This would get actual education level from user profile
        // For now, return a placeholder value
        $levels = ['high_school', 'associate', 'bachelor', 'master', 'phd'];
        return $levels[array_rand($levels)];
    }

    /**
     * Compare education levels
     */
    private function compareEducationLevel($actual, $required)
    {
        $levels = [
            'high_school' => 1,
            'associate' => 2,
            'bachelor' => 3,
            'master' => 4,
            'phd' => 5
        ];

        return ($levels[$actual] ?? 0) >= ($levels[$required] ?? 0);
    }

    /**
     * Get industry experience
     */
    private function getIndustryExperience($instructorId)
    {
        // This would get actual industry experience from user profile
        // For now, return a placeholder value
        return rand(1, 10);
    }

    /**
     * Get knowledge test score
     */
    private function getKnowledgeTestScore($instructorId)
    {
        // This would get actual knowledge test score
        // For now, return a placeholder value
        return rand(70, 100);
    }

    /**
     * Get certification types
     */
    public function getCertificationTypes()
    {
        return $this->certificationTypes;
    }

    /**
     * Get certification levels
     */
    public function getCertificationLevels()
    {
        return $this->certificationLevels;
    }

    /**
     * Clear certification cache
     */
    private function clearCertificationCache($instructorId)
    {
        Cache::forget("instructor_certifications_{$instructorId}");
        Cache::forget("certification_summary_{$instructorId}");
    }

    /**
     * Check expiring certifications
     */
    public function checkExpiringCertifications()
    {
        $expiringCertifications = InstructorCertification::where('status', 'active')
            ->where('expiry_date', '>=', now())
            ->where('expiry_date', '<=', now()->addMonths(1))
            ->with('instructor')
            ->get();

        foreach ($expiringCertifications as $certification) {
            $this->notifyExpiringCertification($certification);
        }

        return $expiringCertifications->count();
    }

    /**
     * Notify expiring certification
     */
    private function notifyExpiringCertification($certification)
    {
        // This would integrate with notification system
        Log::info("Expiring certification notification sent", [
            'certification_id' => $certification->id,
            'instructor_id' => $certification->instructor_id,
            'expiry_date' => $certification->expiry_date
        ]);
    }
} 