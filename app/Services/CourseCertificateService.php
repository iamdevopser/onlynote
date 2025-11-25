<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Course;
use App\Models\CourseCertificate;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Models\CertificateTemplate;

class CourseCertificateService
{
    protected $certificateTypes = [
        'completion' => 'Course Completion',
        'achievement' => 'Achievement',
        'excellence' => 'Excellence',
        'participation' => 'Participation',
        'specialization' => 'Specialization'
    ];

    protected $certificateStatuses = [
        'pending' => 'Pending',
        'issued' => 'Issued',
        'expired' => 'Expired',
        'revoked' => 'Revoked'
    ];

    /**
     * Issue course certificate
     */
    public function issueCertificate($enrollmentId, $certificateData = [])
    {
        try {
            $enrollment = CourseEnrollment::with(['course', 'user'])->find($enrollmentId);
            
            if (!$enrollment) {
                return [
                    'success' => false,
                    'message' => 'Enrollment not found'
                ];
            }

            // Check if enrollment is completed
            if ($enrollment->status !== 'completed') {
                return [
                    'success' => false,
                    'message' => 'Course must be completed to issue certificate'
                ];
            }

            // Check if certificate already exists
            $existingCertificate = CourseCertificate::where('enrollment_id', $enrollmentId)->first();
            if ($existingCertificate) {
                return [
                    'success' => false,
                    'message' => 'Certificate already exists for this enrollment'
                ];
            }

            // Validate certificate requirements
            $requirementsCheck = $this->checkCertificateRequirements($enrollment);
            if (!$requirementsCheck['meets_requirements']) {
                return [
                    'success' => false,
                    'message' => 'Certificate requirements not met',
                    'details' => $requirementsCheck['details']
                ];
            }

            DB::beginTransaction();

            try {
                // Create certificate record
                $certificate = CourseCertificate::create([
                    'enrollment_id' => $enrollmentId,
                    'course_id' => $enrollment->course_id,
                    'user_id' => $enrollment->user_id,
                    'certificate_number' => $this->generateCertificateNumber(),
                    'certificate_type' => $certificateData['certificate_type'] ?? 'completion',
                    'title' => $certificateData['title'] ?? $enrollment->course->title,
                    'description' => $certificateData['description'] ?? 'Certificate of Completion',
                    'issued_date' => now(),
                    'expiry_date' => $this->calculateExpiryDate($enrollment->course),
                    'status' => 'issued',
                    'final_score' => $enrollment->final_score,
                    'completion_date' => $enrollment->completed_at,
                    'metadata' => array_merge($certificateData['metadata'] ?? [], [
                        'course_duration' => $enrollment->course->estimated_duration,
                        'instructor_name' => $enrollment->course->instructor->name ?? 'Unknown',
                        'course_category' => $enrollment->course->category->name ?? 'Unknown'
                    ])
                ]);

                // Generate certificate PDF
                $pdfPath = $this->generateCertificatePDF($certificate);
                if ($pdfPath) {
                    $certificate->pdf_path = $pdfPath;
                    $certificate->save();
                }

                // Generate certificate image
                $imagePath = $this->generateCertificateImage($certificate);
                if ($imagePath) {
                    $certificate->image_path = $imagePath;
                    $certificate->save();
                }

                DB::commit();

                Log::info("Course certificate issued", [
                    'certificate_id' => $certificate->id,
                    'enrollment_id' => $enrollmentId,
                    'user_id' => $enrollment->user_id,
                    'course_id' => $enrollment->course_id
                ]);

                return [
                    'success' => true,
                    'certificate' => $certificate,
                    'message' => 'Certificate issued successfully'
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error("Failed to issue certificate: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to issue certificate'
            ];
        }
    }

    /**
     * Check certificate requirements
     */
    private function checkCertificateRequirements($enrollment)
    {
        $course = $enrollment->course;
        $requirements = $course->certificate_requirements ?? [];

        if (empty($requirements)) {
            return [
                'meets_requirements' => true,
                'details' => 'No specific requirements'
            ];
        }

        $meetsRequirements = true;
        $details = [];

        // Check minimum score requirement
        if (isset($requirements['minimum_score'])) {
            $userScore = $enrollment->final_score ?? 0;
            $requiredScore = $requirements['minimum_score'];
            
            if ($userScore < $requiredScore) {
                $meetsRequirements = false;
                $details[] = "Minimum score not met: {$userScore}/{$requiredScore}";
            } else {
                $details[] = "Minimum score met: {$userScore}/{$requiredScore}";
            }
        }

        // Check completion time requirement
        if (isset($requirements['minimum_completion_time'])) {
            $enrollmentDate = $enrollment->enrolled_at;
            $completionDate = $enrollment->completed_at;
            $completionTime = $enrollmentDate->diffInDays($completionDate);
            $requiredTime = $requirements['minimum_completion_time'];
            
            if ($completionTime < $requiredTime) {
                $meetsRequirements = false;
                $details[] = "Minimum completion time not met: {$completionTime} days (required: {$requiredTime})";
            } else {
                $details[] = "Minimum completion time met: {$completionTime} days (required: {$requiredTime})";
            }
        }

        // Check attendance requirement
        if (isset($requirements['minimum_attendance'])) {
            $attendancePercentage = $this->calculateAttendancePercentage($enrollment);
            $requiredAttendance = $requirements['minimum_attendance'];
            
            if ($attendancePercentage < $requiredAttendance) {
                $meetsRequirements = false;
                $details[] = "Minimum attendance not met: {$attendancePercentage}% (required: {$requiredAttendance}%)";
            } else {
                $details[] = "Minimum attendance met: {$attendancePercentage}% (required: {$requiredAttendance}%)";
            }
        }

        // Check assignment completion requirement
        if (isset($requirements['complete_assignments'])) {
            $assignmentCompletion = $this->calculateAssignmentCompletion($enrollment);
            $requiredCompletion = $requirements['complete_assignments'];
            
            if ($assignmentCompletion < $requiredCompletion) {
                $meetsRequirements = false;
                $details[] = "Assignment completion not met: {$assignmentCompletion}% (required: {$requiredCompletion}%)";
            } else {
                $details[] = "Assignment completion met: {$assignmentCompletion}% (required: {$requiredCompletion}%)";
            }
        }

        return [
            'meets_requirements' => $meetsRequirements,
            'details' => $details
        ];
    }

    /**
     * Calculate attendance percentage
     */
    private function calculateAttendancePercentage($enrollment)
    {
        // This would calculate actual attendance from lesson progress
        // For now, return a placeholder value
        return rand(80, 100);
    }

    /**
     * Calculate assignment completion
     */
    private function calculateAssignmentCompletion($enrollment)
    {
        // This would calculate actual assignment completion
        // For now, return a placeholder value
        return rand(70, 100);
    }

    /**
     * Generate certificate number
     */
    private function generateCertificateNumber()
    {
        do {
            $number = 'CERT-' . date('Y') . '-' . Str::random(8);
        } while (CourseCertificate::where('certificate_number', $number)->exists());

        return $number;
    }

    /**
     * Calculate expiry date
     */
    private function calculateExpiryDate($course)
    {
        $validityPeriod = $course->certificate_validity ?? 365; // days
        return now()->addDays($validityPeriod);
    }

    /**
     * Generate certificate PDF
     */
    private function generateCertificatePDF($certificate)
    {
        try {
            // This would use a PDF library like Dompdf or mPDF
            // For now, return a placeholder path
            $filename = "certificates/{$certificate->id}/certificate_{$certificate->certificate_number}.pdf";
            
            // Create directory if it doesn't exist
            $directory = dirname(storage_path("app/public/{$filename}"));
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Placeholder PDF content
            $pdfContent = $this->generatePDFContent($certificate);
            Storage::disk('public')->put($filename, $pdfContent);

            return $filename;

        } catch (\Exception $e) {
            Log::error("Failed to generate certificate PDF: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate PDF content
     */
    private function generatePDFContent($certificate)
    {
        // This would generate actual PDF content
        // For now, return placeholder content
        $content = "Certificate of Completion\n\n";
        $content .= "This is to certify that\n";
        $content .= "{$certificate->user->name}\n";
        $content .= "has successfully completed the course\n";
        $content .= "{$certificate->title}\n\n";
        $content .= "Certificate Number: {$certificate->certificate_number}\n";
        $content .= "Issue Date: {$certificate->issued_date->format('Y-m-d')}\n";
        $content .= "Final Score: {$certificate->final_score}%\n";

        return $content;
    }

    /**
     * Generate certificate image
     */
    private function generateCertificateImage($certificate)
    {
        try {
            // This would generate an actual certificate image
            // For now, return a placeholder path
            $filename = "certificates/{$certificate->id}/certificate_{$certificate->certificate_number}.png";
            
            // Create directory if it doesn't exist
            $directory = dirname(storage_path("app/public/{$filename}"));
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Placeholder image content
            $imageContent = $this->generateImageContent($certificate);
            Storage::disk('public')->put($filename, $imageContent);

            return $filename;

        } catch (\Exception $e) {
            Log::error("Failed to generate certificate image: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate image content
     */
    private function generateImageContent($certificate)
    {
        // This would generate actual image content
        // For now, return placeholder content
        return "Certificate Image Placeholder";
    }

    /**
     * Get user certificates
     */
    public function getUserCertificates($userId, $filters = [])
    {
        $query = CourseCertificate::where('user_id', $userId);

        // Apply filters
        if (isset($filters['certificate_type'])) {
            $query->where('certificate_type', $filters['certificate_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['course_id'])) {
            $query->where('course_id', $filters['course_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('issued_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('issued_date', '<=', $filters['date_to']);
        }

        return $query->with(['course', 'enrollment'])
            ->orderBy('issued_date', 'desc')
            ->paginate(20);
    }

    /**
     * Get certificate by ID
     */
    public function getCertificate($certificateId)
    {
        return CourseCertificate::with(['course', 'enrollment', 'user'])->find($certificateId);
    }

    /**
     * Get certificate by number
     */
    public function getCertificateByNumber($certificateNumber)
    {
        return CourseCertificate::with(['course', 'enrollment', 'user'])
            ->where('certificate_number', $certificateNumber)
            ->first();
    }

    /**
     * Update certificate
     */
    public function updateCertificate($certificateId, $data)
    {
        try {
            $certificate = CourseCertificate::find($certificateId);
            
            if (!$certificate) {
                return [
                    'success' => false,
                    'message' => 'Certificate not found'
                ];
            }

            // Update fields
            $updatableFields = [
                'title', 'description', 'certificate_type', 'status', 'metadata'
            ];

            foreach ($updatableFields as $field) {
                if (isset($data[$field])) {
                    $certificate->$field = $data[$field];
                }
            }

            $certificate->updated_at = now();
            $certificate->save();

            Log::info("Course certificate updated", [
                'certificate_id' => $certificateId,
                'user_id' => $certificate->user_id
            ]);

            return [
                'success' => true,
                'certificate' => $certificate,
                'message' => 'Certificate updated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to update certificate: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update certificate'
            ];
        }
    }

    /**
     * Revoke certificate
     */
    public function revokeCertificate($certificateId, $reason = null)
    {
        try {
            $certificate = CourseCertificate::find($certificateId);
            
            if (!$certificate) {
                return [
                    'success' => false,
                    'message' => 'Certificate not found'
                ];
            }

            $certificate->status = 'revoked';
            $certificate->revoked_at = now();
            $certificate->revocation_reason = $reason;
            $certificate->save();

            Log::info("Course certificate revoked", [
                'certificate_id' => $certificateId,
                'user_id' => $certificate->user_id,
                'reason' => $reason
            ]);

            return [
                'success' => true,
                'certificate' => $certificate,
                'message' => 'Certificate revoked successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to revoke certificate: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to revoke certificate'
            ];
        }
    }

    /**
     * Renew certificate
     */
    public function renewCertificate($certificateId)
    {
        try {
            $certificate = CourseCertificate::find($certificateId);
            
            if (!$certificate) {
                return [
                    'success' => false,
                    'message' => 'Certificate not found'
                ];
            }

            if ($certificate->status !== 'expired') {
                return [
                    'success' => false,
                    'message' => 'Certificate is not expired'
                ];
            }

            // Create new certificate
            $newCertificate = $certificate->replicate();
            $newCertificate->certificate_number = $this->generateCertificateNumber();
            $newCertificate->issued_date = now();
            $newCertificate->expiry_date = $this->calculateExpiryDate($certificate->course);
            $newCertificate->status = 'issued';
            $newCertificate->renewed_from = $certificate->id;
            $newCertificate->save();

            // Generate new PDF and image
            $pdfPath = $this->generateCertificatePDF($newCertificate);
            if ($pdfPath) {
                $newCertificate->pdf_path = $pdfPath;
                $newCertificate->save();
            }

            $imagePath = $this->generateCertificateImage($newCertificate);
            if ($imagePath) {
                $newCertificate->image_path = $imagePath;
                $newCertificate->save();
            }

            Log::info("Course certificate renewed", [
                'old_certificate_id' => $certificateId,
                'new_certificate_id' => $newCertificate->id,
                'user_id' => $certificate->user_id
            ]);

            return [
                'success' => true,
                'certificate' => $newCertificate,
                'message' => 'Certificate renewed successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to renew certificate: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to renew certificate'
            ];
        }
    }

    /**
     * Verify certificate
     */
    public function verifyCertificate($certificateNumber)
    {
        try {
            $certificate = $this->getCertificateByNumber($certificateNumber);
            
            if (!$certificate) {
                return [
                    'valid' => false,
                    'message' => 'Certificate not found',
                    'details' => null
                ];
            }

            $verificationResult = [
                'valid' => true,
                'message' => 'Certificate is valid',
                'details' => [
                    'certificate_number' => $certificate->certificate_number,
                    'user_name' => $certificate->user->name,
                    'course_title' => $certificate->course->title,
                    'issued_date' => $certificate->issued_date->format('Y-m-d'),
                    'expiry_date' => $certificate->expiry_date->format('Y-m-d'),
                    'status' => $certificate->status,
                    'final_score' => $certificate->final_score
                ]
            ];

            // Check if certificate is expired
            if ($certificate->expiry_date < now()) {
                $verificationResult['valid'] = false;
                $verificationResult['message'] = 'Certificate has expired';
                $verificationResult['details']['expired'] = true;
            }

            // Check if certificate is revoked
            if ($certificate->status === 'revoked') {
                $verificationResult['valid'] = false;
                $verificationResult['message'] = 'Certificate has been revoked';
                $verificationResult['details']['revoked'] = true;
                $verificationResult['details']['revocation_reason'] = $certificate->revocation_reason;
            }

            return $verificationResult;

        } catch (\Exception $e) {
            Log::error("Failed to verify certificate: " . $e->getMessage());
            return [
                'valid' => false,
                'message' => 'Verification failed',
                'details' => null
            ];
        }
    }

    /**
     * Get certificate statistics
     */
    public function getCertificateStats($courseId = null, $instructorId = null)
    {
        $query = CourseCertificate::query();

        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        if ($instructorId) {
            $query->join('courses', 'course_certificates.course_id', '=', 'courses.id')
                  ->where('courses.instructor_id', $instructorId);
        }

        $stats = [
            'total_certificates' => $query->count(),
            'certificates_by_type' => $query->selectRaw('certificate_type, COUNT(*) as count')
                ->groupBy('certificate_type')
                ->pluck('count', 'certificate_type'),
            'certificates_by_status' => $query->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'certificates_by_month' => $query->selectRaw('DATE_FORMAT(issued_date, "%Y-%m") as month, COUNT(*) as count')
                ->groupBy('month')
                ->orderBy('month', 'desc')
                ->pluck('count', 'month'),
            'average_score' => round($query->avg('final_score'), 2),
            'expired_certificates' => $query->where('expiry_date', '<', now())->count(),
            'expiring_soon' => $query->where('expiry_date', '>=', now())
                ->where('expiry_date', '<=', now()->addDays(30))
                ->count()
        ];

        return $stats;
    }

    /**
     * Export certificates
     */
    public function exportCertificates($filters = [], $format = 'csv')
    {
        $query = CourseCertificate::with(['course', 'user']);

        // Apply filters
        if (isset($filters['course_id'])) {
            $query->where('course_id', $filters['course_id']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('issued_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('issued_date', '<=', $filters['date_to']);
        }

        $certificates = $query->get();

        switch ($format) {
            case 'csv':
                return $this->exportToCSV($certificates);
            case 'json':
                return $this->exportToJSON($certificates);
            case 'excel':
                return $this->exportToExcel($certificates);
            default:
                return $this->exportToCSV($certificates);
        }
    }

    /**
     * Export to CSV
     */
    private function exportToCSV($certificates)
    {
        $filename = 'course_certificates_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $filepath = storage_path('app/exports/' . $filename);

        $handle = fopen($filepath, 'w');

        // Add headers
        fputcsv($handle, [
            'Certificate Number', 'User Name', 'Course Title', 'Certificate Type',
            'Issue Date', 'Expiry Date', 'Status', 'Final Score', 'Completion Date'
        ]);

        // Add data
        foreach ($certificates as $certificate) {
            fputcsv($handle, [
                $certificate->certificate_number,
                $certificate->user->name ?? 'N/A',
                $certificate->course->title ?? 'N/A',
                $certificate->certificate_type,
                $certificate->issued_date->format('Y-m-d'),
                $certificate->expiry_date->format('Y-m-d'),
                $certificate->status,
                $certificate->final_score,
                $certificate->completion_date ? $certificate->completion_date->format('Y-m-d') : 'N/A'
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
     * Export to JSON
     */
    private function exportToJSON($certificates)
    {
        $data = $certificates->map(function ($certificate) {
            return [
                'certificate_number' => $certificate->certificate_number,
                'user_name' => $certificate->user->name ?? 'N/A',
                'course_title' => $certificate->course->title ?? 'N/A',
                'certificate_type' => $certificate->certificate_type,
                'issue_date' => $certificate->issued_date->format('Y-m-d'),
                'expiry_date' => $certificate->expiry_date->format('Y-m-d'),
                'status' => $certificate->status,
                'final_score' => $certificate->final_score,
                'completion_date' => $certificate->completion_date ? $certificate->completion_date->format('Y-m-d') : 'N/A'
            ];
        });

        $filename = 'course_certificates_' . now()->format('Y-m-d_H-i-s') . '.json';
        $filepath = storage_path('app/exports/' . $filename);

        file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => $filename
        ];
    }

    /**
     * Export to Excel
     */
    private function exportToExcel($certificates)
    {
        // This would require a package like PhpSpreadsheet
        // For now, return CSV format
        return $this->exportToCSV($certificates);
    }

    /**
     * Get certificate types
     */
    public function getCertificateTypes()
    {
        return $this->certificateTypes;
    }

    /**
     * Get certificate statuses
     */
    public function getCertificateStatuses()
    {
        return $this->certificateStatuses;
    }

    /**
     * Check expiring certificates
     */
    public function checkExpiringCertificates()
    {
        $expiringCertificates = CourseCertificate::where('status', 'issued')
            ->where('expiry_date', '>=', now())
            ->where('expiry_date', '<=', now()->addDays(30))
            ->with(['user', 'course'])
            ->get();

        foreach ($expiringCertificates as $certificate) {
            $this->notifyExpiringCertificate($certificate);
        }

        return $expiringCertificates->count();
    }

    /**
     * Notify expiring certificate
     */
    private function notifyExpiringCertificate($certificate)
    {
        // This would integrate with notification system
        Log::info("Expiring certificate notification sent", [
            'certificate_id' => $certificate->id,
            'user_id' => $certificate->user_id,
            'expiry_date' => $certificate->expiry_date
        ]);
    }
} 