<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\InstructorDocument;
use App\Models\User;

class InstructorDocumentService
{
    protected $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip'];
    protected $maxFileSize = 10240; // 10MB
    
    /**
     * Upload instructor document
     */
    public function uploadDocument($file, $instructorId, $documentType, $metadata = [])
    {
        try {
            // Validate file
            $validation = $this->validateDocument($file);
            if (!$validation['valid']) {
                return $validation;
            }

            // Generate unique filename
            $filename = $this->generateFilename($file);
            $path = "instructor-documents/{$instructorId}/{$documentType}";
            
            // Store file
            $filePath = Storage::disk('public')->putFileAs($path, $file, $filename);
            
            if (!$filePath) {
                return [
                    'success' => false,
                    'message' => 'Failed to store document'
                ];
            }

            // Create document record
            $document = InstructorDocument::create([
                'instructor_id' => $instructorId,
                'document_type' => $documentType,
                'filename' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'status' => 'pending',
                'metadata' => $metadata
            ]);

            Log::info("Document uploaded successfully", [
                'instructor_id' => $instructorId,
                'document_id' => $document->id,
                'type' => $documentType
            ]);

            return [
                'success' => true,
                'document' => $document,
                'message' => 'Document uploaded successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Document upload failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Document upload failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate document
     */
    private function validateDocument($file)
    {
        // Check file size
        if ($file->getSize() > $this->maxFileSize * 1024) {
            return [
                'valid' => false,
                'message' => "File size exceeds maximum limit of {$this->maxFileSize}MB"
            ];
        }

        // Check file type
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $this->allowedTypes)) {
            return [
                'valid' => false,
                'message' => "File type '{$extension}' is not allowed. Allowed types: " . implode(', ', $this->allowedTypes)
            ];
        }

        // Check for malicious files
        if ($this->isMaliciousFile($file)) {
            return [
                'valid' => false,
                'message' => 'File appears to be malicious and cannot be uploaded'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Check if file is malicious
     */
    private function isMaliciousFile($file)
    {
        $content = file_get_contents($file->getRealPath());
        
        // Check for common malicious patterns
        $maliciousPatterns = [
            '<?php',
            'eval(',
            'exec(',
            'system(',
            'shell_exec(',
            'passthru(',
            'base64_decode('
        ];

        foreach ($maliciousPatterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate unique filename
     */
    private function generateFilename($file)
    {
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('Y-m-d_H-i-s');
        $random = Str::random(8);
        
        return "doc_{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Get instructor documents
     */
    public function getInstructorDocuments($instructorId, $filters = [])
    {
        $query = InstructorDocument::where('instructor_id', $instructorId);

        // Apply filters
        if (isset($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Get document by ID
     */
    public function getDocument($documentId)
    {
        return InstructorDocument::with('instructor')->find($documentId);
    }

    /**
     * Update document status
     */
    public function updateDocumentStatus($documentId, $status, $adminId = null, $notes = null)
    {
        try {
            $document = InstructorDocument::find($documentId);
            
            if (!$document) {
                return [
                    'success' => false,
                    'message' => 'Document not found'
                ];
            }

            $oldStatus = $document->status;
            $document->status = $status;
            
            if ($adminId) {
                $document->reviewed_by = $adminId;
                $document->reviewed_at = now();
            }
            
            if ($notes) {
                $document->admin_notes = $notes;
            }

            $document->save();

            // Log status change
            Log::info("Document status updated", [
                'document_id' => $documentId,
                'old_status' => $oldStatus,
                'new_status' => $status,
                'admin_id' => $adminId
            ]);

            return [
                'success' => true,
                'document' => $document,
                'message' => 'Document status updated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to update document status: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update document status'
            ];
        }
    }

    /**
     * Delete document
     */
    public function deleteDocument($documentId)
    {
        try {
            $document = InstructorDocument::find($documentId);
            
            if (!$document) {
                return [
                    'success' => false,
                    'message' => 'Document not found'
                ];
            }

            // Delete file from storage
            if (Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }

            // Delete database record
            $document->delete();

            Log::info("Document deleted successfully", [
                'document_id' => $documentId,
                'instructor_id' => $document->instructor_id
            ]);

            return [
                'success' => true,
                'message' => 'Document deleted successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to delete document: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete document'
            ];
        }
    }

    /**
     * Get document statistics
     */
    public function getDocumentStats($instructorId = null)
    {
        $query = InstructorDocument::query();

        if ($instructorId) {
            $query->where('instructor_id', $instructorId);
        }

        $stats = [
            'total_documents' => $query->count(),
            'pending_documents' => $query->where('status', 'pending')->count(),
            'approved_documents' => $query->where('status', 'approved')->count(),
            'rejected_documents' => $query->where('status', 'rejected')->count(),
            'total_size' => $query->sum('file_size'),
            'documents_by_type' => $query->selectRaw('document_type, COUNT(*) as count')
                ->groupBy('document_type')
                ->pluck('count', 'document_type')
        ];

        return $stats;
    }

    /**
     * Bulk update document status
     */
    public function bulkUpdateStatus($documentIds, $status, $adminId, $notes = null)
    {
        try {
            $documents = InstructorDocument::whereIn('id', $documentIds)->get();
            
            foreach ($documents as $document) {
                $document->status = $status;
                $document->reviewed_by = $adminId;
                $document->reviewed_at = now();
                
                if ($notes) {
                    $document->admin_notes = $notes;
                }
                
                $document->save();
            }

            Log::info("Bulk document status update", [
                'document_ids' => $documentIds,
                'new_status' => $status,
                'admin_id' => $adminId,
                'count' => count($documents)
            ]);

            return [
                'success' => true,
                'message' => "Updated {$documents->count()} documents successfully"
            ];

        } catch (\Exception $e) {
            Log::error("Bulk update failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Bulk update failed'
            ];
        }
    }

    /**
     * Export documents
     */
    public function exportDocuments($filters = [], $format = 'csv')
    {
        $query = InstructorDocument::with('instructor');

        // Apply filters
        if (isset($filters['instructor_id'])) {
            $query->where('instructor_id', $filters['instructor_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }

        $documents = $query->get();

        switch ($format) {
            case 'csv':
                return $this->exportToCSV($documents);
            case 'json':
                return $this->exportToJSON($documents);
            case 'excel':
                return $this->exportToExcel($documents);
            default:
                return $this->exportToCSV($documents);
        }
    }

    /**
     * Export to CSV
     */
    private function exportToCSV($documents)
    {
        $filename = 'instructor_documents_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $filepath = storage_path('app/exports/' . $filename);

        $handle = fopen($filepath, 'w');

        // Add headers
        fputcsv($handle, [
            'ID', 'Instructor', 'Document Type', 'Filename', 'Status', 
            'File Size', 'Upload Date', 'Review Date', 'Admin Notes'
        ]);

        // Add data
        foreach ($documents as $document) {
            fputcsv($handle, [
                $document->id,
                $document->instructor->name ?? 'N/A',
                $document->document_type,
                $document->original_name,
                $document->status,
                $this->formatFileSize($document->file_size),
                $document->created_at->format('Y-m-d H:i:s'),
                $document->reviewed_at ? $document->reviewed_at->format('Y-m-d H:i:s') : 'N/A',
                $document->admin_notes ?? 'N/A'
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
    private function exportToJSON($documents)
    {
        $data = $documents->map(function ($document) {
            return [
                'id' => $document->id,
                'instructor' => $document->instructor->name ?? 'N/A',
                'document_type' => $document->document_type,
                'filename' => $document->original_name,
                'status' => $document->status,
                'file_size' => $this->formatFileSize($document->file_size),
                'upload_date' => $document->created_at->format('Y-m-d H:i:s'),
                'review_date' => $document->reviewed_at ? $document->reviewed_at->format('Y-m-d H:i:s') : 'N/A',
                'admin_notes' => $document->admin_notes ?? 'N/A'
            ];
        });

        $filename = 'instructor_documents_' . now()->format('Y-m-d_H-i-s') . '.json';
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
    private function exportToExcel($documents)
    {
        // This would require a package like PhpSpreadsheet
        // For now, return CSV format
        return $this->exportToCSV($documents);
    }

    /**
     * Format file size
     */
    private function formatFileSize($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Get document types
     */
    public function getDocumentTypes()
    {
        return [
            'identity' => 'Identity Document',
            'education' => 'Education Certificate',
            'experience' => 'Experience Certificate',
            'portfolio' => 'Portfolio',
            'other' => 'Other'
        ];
    }

    /**
     * Get document statuses
     */
    public function getDocumentStatuses()
    {
        return [
            'pending' => 'Pending Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'expired' => 'Expired'
        ];
    }

    /**
     * Check document expiration
     */
    public function checkDocumentExpiration()
    {
        $expiredDocuments = InstructorDocument::where('status', 'approved')
            ->where('created_at', '<=', now()->subYear())
            ->get();

        foreach ($expiredDocuments as $document) {
            $document->status = 'expired';
            $document->save();

            // Notify instructor about expired document
            $this->notifyDocumentExpiration($document);
        }

        return $expiredDocuments->count();
    }

    /**
     * Notify document expiration
     */
    private function notifyDocumentExpiration($document)
    {
        // This would integrate with notification system
        Log::info("Document expired notification sent", [
            'document_id' => $document->id,
            'instructor_id' => $document->instructor_id
        ]);
    }
} 