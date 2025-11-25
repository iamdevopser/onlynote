<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use App\Models\InstructorCommunication;
use App\Models\User;
use App\Models\Course;

class InstructorCommunicationService
{
    protected $communicationTypes = [
        'support_request' => 'Support Request',
        'course_inquiry' => 'Course Inquiry',
        'technical_issue' => 'Technical Issue',
        'billing_question' => 'Billing Question',
        'partnership' => 'Partnership Inquiry',
        'feedback' => 'Feedback',
        'general' => 'General Inquiry'
    ];

    protected $priorityLevels = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent'
    ];

    protected $statuses = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'waiting_response' => 'Waiting for Response',
        'resolved' => 'Resolved',
        'closed' => 'Closed'
    ];

    /**
     * Create communication request
     */
    public function createCommunication($data)
    {
        try {
            $communication = InstructorCommunication::create([
                'instructor_id' => $data['instructor_id'],
                'communication_type' => $data['communication_type'],
                'subject' => $data['subject'],
                'message' => $data['message'],
                'priority' => $data['priority'] ?? 'medium',
                'status' => 'open',
                'assigned_to' => $data['assigned_to'] ?? null,
                'category' => $data['category'] ?? 'general',
                'attachments' => $data['attachments'] ?? [],
                'metadata' => $data['metadata'] ?? []
            ]);

            // Send notification to admin
            $this->notifyAdmin($communication);

            // Auto-assign if high priority
            if ($data['priority'] === 'urgent') {
                $this->autoAssignToAdmin($communication);
            }

            Log::info("Communication request created", [
                'communication_id' => $communication->id,
                'instructor_id' => $data['instructor_id'],
                'type' => $data['communication_type']
            ]);

            return [
                'success' => true,
                'communication' => $communication,
                'message' => 'Communication request created successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to create communication: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create communication request'
            ];
        }
    }

    /**
     * Get instructor communications
     */
    public function getInstructorCommunications($instructorId, $filters = [])
    {
        $cacheKey = "instructor_communications_{$instructorId}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $query = InstructorCommunication::where('instructor_id', $instructorId);

        // Apply filters
        if (isset($filters['communication_type'])) {
            $query->where('communication_type', $filters['communication_type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $communications = $query->with(['instructor', 'assignedTo'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        Cache::put($cacheKey, $communications, 1800);

        return $communications;
    }

    /**
     * Get communication by ID
     */
    public function getCommunication($communicationId)
    {
        return InstructorCommunication::with(['instructor', 'assignedTo', 'responses'])->find($communicationId);
    }

    /**
     * Update communication
     */
    public function updateCommunication($communicationId, $data)
    {
        try {
            $communication = InstructorCommunication::find($communicationId);
            
            if (!$communication) {
                return [
                    'success' => false,
                    'message' => 'Communication not found'
                ];
            }

            // Update fields
            $updatableFields = [
                'subject', 'message', 'priority', 'status', 'category', 'metadata'
            ];

            foreach ($updatableFields as $field) {
                if (isset($data[$field])) {
                    $communication->$field = $data[$field];
                }
            }

            $communication->updated_at = now();
            $communication->save();

            // Clear cache
            $this->clearCommunicationCache($communication->instructor_id);

            Log::info("Communication updated", [
                'communication_id' => $communicationId,
                'instructor_id' => $communication->instructor_id
            ]);

            return [
                'success' => true,
                'communication' => $communication,
                'message' => 'Communication updated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to update communication: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update communication'
            ];
        }
    }

    /**
     * Add response to communication
     */
    public function addResponse($communicationId, $data)
    {
        try {
            $communication = InstructorCommunication::find($communicationId);
            
            if (!$communication) {
                return [
                    'success' => false,
                    'message' => 'Communication not found'
                ];
            }

            $response = $communication->responses()->create([
                'responder_id' => $data['responder_id'],
                'responder_type' => $data['responder_type'], // admin, instructor
                'message' => $data['message'],
                'attachments' => $data['attachments'] ?? [],
                'is_internal' => $data['is_internal'] ?? false
            ]);

            // Update communication status
            if ($data['responder_type'] === 'admin') {
                $communication->status = 'waiting_response';
            } else {
                $communication->status = 'in_progress';
            }

            $communication->last_response_at = now();
            $communication->save();

            // Clear cache
            $this->clearCommunicationCache($communication->instructor_id);

            // Send notification
            $this->notifyResponse($communication, $response);

            Log::info("Response added to communication", [
                'communication_id' => $communicationId,
                'response_id' => $response->id,
                'responder_type' => $data['responder_type']
            ]);

            return [
                'success' => true,
                'response' => $response,
                'message' => 'Response added successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to add response: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to add response'
            ];
        }
    }

    /**
     * Assign communication to admin
     */
    public function assignCommunication($communicationId, $adminId)
    {
        try {
            $communication = InstructorCommunication::find($communicationId);
            
            if (!$communication) {
                return [
                    'success' => false,
                    'message' => 'Communication not found'
                ];
            }

            $communication->assigned_to = $adminId;
            $communication->assigned_at = now();
            $communication->status = 'in_progress';
            $communication->save();

            // Clear cache
            $this->clearCommunicationCache($communication->instructor_id);

            Log::info("Communication assigned", [
                'communication_id' => $communicationId,
                'admin_id' => $adminId
            ]);

            return [
                'success' => true,
                'communication' => $communication,
                'message' => 'Communication assigned successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to assign communication: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to assign communication'
            ];
        }
    }

    /**
     * Close communication
     */
    public function closeCommunication($communicationId, $adminId, $resolution = null)
    {
        try {
            $communication = InstructorCommunication::find($communicationId);
            
            if (!$communication) {
                return [
                    'success' => false,
                    'message' => 'Communication not found'
                ];
            }

            $communication->status = 'resolved';
            $communication->resolved_by = $adminId;
            $communication->resolved_at = now();
            $communication->resolution = $resolution;
            $communication->save();

            // Clear cache
            $this->clearCommunicationCache($communication->instructor_id);

            // Send resolution notification
            $this->notifyResolution($communication);

            Log::info("Communication resolved", [
                'communication_id' => $communicationId,
                'admin_id' => $adminId
            ]);

            return [
                'success' => true,
                'communication' => $communication,
                'message' => 'Communication resolved successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to resolve communication: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to resolve communication'
            ];
        }
    }

    /**
     * Get communication statistics
     */
    public function getCommunicationStats($instructorId = null)
    {
        $query = InstructorCommunication::query();

        if ($instructorId) {
            $query->where('instructor_id', $instructorId);
        }

        $stats = [
            'total_communications' => $query->count(),
            'open_communications' => $query->where('status', 'open')->count(),
            'in_progress' => $query->where('status', 'in_progress')->count(),
            'waiting_response' => $query->where('status', 'waiting_response')->count(),
            'resolved' => $query->where('status', 'resolved')->count(),
            'closed' => $query->where('status', 'closed')->count(),
            'communications_by_type' => $query->selectRaw('communication_type, COUNT(*) as count')
                ->groupBy('communication_type')
                ->pluck('count', 'communication_type'),
            'communications_by_priority' => $query->selectRaw('priority, COUNT(*) as count')
                ->groupBy('priority')
                ->pluck('count', 'priority'),
            'average_response_time' => $this->calculateAverageResponseTime($query),
            'satisfaction_score' => $this->calculateSatisfactionScore($query)
        ];

        return $stats;
    }

    /**
     * Calculate average response time
     */
    private function calculateAverageResponseTime($query)
    {
        $communications = $query->whereNotNull('last_response_at')->get();
        
        if ($communications->isEmpty()) {
            return 0;
        }

        $totalTime = 0;
        $count = 0;

        foreach ($communications as $communication) {
            $responseTime = $communication->created_at->diffInHours($communication->last_response_at);
            $totalTime += $responseTime;
            $count++;
        }

        return $count > 0 ? round($totalTime / $count, 2) : 0;
    }

    /**
     * Calculate satisfaction score
     */
    private function calculateSatisfactionScore($query)
    {
        $communications = $query->where('status', 'resolved')->get();
        
        if ($communications->isEmpty()) {
            return 0;
        }

        $totalScore = 0;
        $count = 0;

        foreach ($communications as $communication) {
            if (isset($communication->metadata['satisfaction_score'])) {
                $totalScore += $communication->metadata['satisfaction_score'];
                $count++;
            }
        }

        return $count > 0 ? round($totalScore / $count, 2) : 0;
    }

    /**
     * Search communications
     */
    public function searchCommunications($query, $filters = [])
    {
        $searchQuery = InstructorCommunication::with(['instructor', 'assignedTo']);

        // Apply filters
        if (isset($filters['instructor_id'])) {
            $searchQuery->where('instructor_id', $filters['instructor_id']);
        }

        if (isset($filters['status'])) {
            $searchQuery->where('status', $filters['status']);
        }

        if (isset($filters['priority'])) {
            $searchQuery->where('priority', $filters['priority']);
        }

        if (isset($filters['date_from'])) {
            $searchQuery->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $searchQuery->where('created_at', '<=', $filters['date_to']);
        }

        // Search in subject and message
        $searchQuery->where(function ($q) use ($query) {
            $q->where('subject', 'LIKE', "%{$query}%")
              ->orWhere('message', 'LIKE', "%{$query}%");
        });

        return $searchQuery->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Get communication templates
     */
    public function getCommunicationTemplates()
    {
        return [
            'support_request' => [
                'subject' => 'Support Request - {issue_type}',
                'message' => "Hello,\n\nI'm experiencing the following issue:\n\n{description}\n\nSteps to reproduce:\n{steps}\n\nExpected behavior:\n{expected}\n\nActual behavior:\n{actual}\n\nPlease help me resolve this issue.\n\nBest regards,\n{instructor_name}"
            ],
            'course_inquiry' => [
                'subject' => 'Course Inquiry - {course_name}',
                'message' => "Hello,\n\nI have a question about the course '{course_name}':\n\n{question}\n\nAdditional context:\n{context}\n\nI would appreciate your response.\n\nBest regards,\n{instructor_name}"
            ],
            'technical_issue' => [
                'subject' => 'Technical Issue - {platform_feature}',
                'message' => "Hello,\n\nI'm encountering a technical issue with {platform_feature}:\n\nIssue description:\n{description}\n\nError message:\n{error}\n\nBrowser/Device:\n{device_info}\n\nPlease provide assistance.\n\nBest regards,\n{instructor_name}"
            ],
            'billing_question' => [
                'subject' => 'Billing Question - {transaction_id}',
                'message' => "Hello,\n\nI have a question about my billing:\n\nTransaction ID: {transaction_id}\nAmount: {amount}\nDate: {date}\n\nQuestion:\n{question}\n\nPlease clarify this for me.\n\nBest regards,\n{instructor_name}"
            ]
        ];
    }

    /**
     * Apply template to communication
     */
    public function applyTemplate($templateType, $data)
    {
        $templates = $this->getCommunicationTemplates();
        
        if (!isset($templates[$templateType])) {
            return null;
        }

        $template = $templates[$templateType];
        
        $subject = $template['subject'];
        $message = $template['message'];

        // Replace placeholders
        foreach ($data as $key => $value) {
            $subject = str_replace("{{$key}}", $value, $subject);
            $message = str_replace("{{$key}}", $value, $message);
        }

        return [
            'subject' => $subject,
            'message' => $message
        ];
    }

    /**
     * Notify admin about new communication
     */
    private function notifyAdmin($communication)
    {
        // This would integrate with notification system
        Log::info("Admin notification sent for new communication", [
            'communication_id' => $communication->id,
            'instructor_id' => $communication->instructor_id
        ]);
    }

    /**
     * Auto-assign urgent communications
     */
    private function autoAssignToAdmin($communication)
    {
        // Find available admin
        $admin = User::where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('communication_count', 'asc')
            ->first();

        if ($admin) {
            $this->assignCommunication($communication->id, $admin->id);
        }
    }

    /**
     * Notify about response
     */
    private function notifyResponse($communication, $response)
    {
        // This would integrate with notification system
        Log::info("Response notification sent", [
            'communication_id' => $communication->id,
            'response_id' => $response->id
        ]);
    }

    /**
     * Notify about resolution
     */
    private function notifyResolution($communication)
    {
        // This would integrate with notification system
        Log::info("Resolution notification sent", [
            'communication_id' => $communication->id,
            'instructor_id' => $communication->instructor_id
        ]);
    }

    /**
     * Clear communication cache
     */
    private function clearCommunicationCache($instructorId)
    {
        Cache::forget("instructor_communications_{$instructorId}");
    }

    /**
     * Get communication types
     */
    public function getCommunicationTypes()
    {
        return $this->communicationTypes;
    }

    /**
     * Get priority levels
     */
    public function getPriorityLevels()
    {
        return $this->priorityLevels;
    }

    /**
     * Get statuses
     */
    public function getStatuses()
    {
        return $this->statuses;
    }

    /**
     * Export communications
     */
    public function exportCommunications($filters = [], $format = 'csv')
    {
        $query = InstructorCommunication::with(['instructor', 'assignedTo']);

        // Apply filters
        if (isset($filters['instructor_id'])) {
            $query->where('instructor_id', $filters['instructor_id']);
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

        $communications = $query->get();

        switch ($format) {
            case 'csv':
                return $this->exportToCSV($communications);
            case 'json':
                return $this->exportToJSON($communications);
            case 'excel':
                return $this->exportToExcel($communications);
            default:
                return $this->exportToCSV($communications);
        }
    }

    /**
     * Export to CSV
     */
    private function exportToCSV($communications)
    {
        $filename = 'instructor_communications_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $filepath = storage_path('app/exports/' . $filename);

        $handle = fopen($filepath, 'w');

        // Add headers
        fputcsv($handle, [
            'ID', 'Instructor', 'Type', 'Subject', 'Status', 'Priority',
            'Created Date', 'Assigned To', 'Last Response', 'Resolution'
        ]);

        // Add data
        foreach ($communications as $communication) {
            fputcsv($handle, [
                $communication->id,
                $communication->instructor->name ?? 'N/A',
                $communication->communication_type,
                $communication->subject,
                $communication->status,
                $communication->priority,
                $communication->created_at->format('Y-m-d H:i:s'),
                $communication->assignedTo->name ?? 'Unassigned',
                $communication->last_response_at ? $communication->last_response_at->format('Y-m-d H:i:s') : 'No Response',
                $communication->resolution ?? 'N/A'
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
    private function exportToJSON($communications)
    {
        $data = $communications->map(function ($communication) {
            return [
                'id' => $communication->id,
                'instructor' => $communication->instructor->name ?? 'N/A',
                'type' => $communication->communication_type,
                'subject' => $communication->subject,
                'status' => $communication->status,
                'priority' => $communication->priority,
                'created_date' => $communication->created_at->format('Y-m-d H:i:s'),
                'assigned_to' => $communication->assignedTo->name ?? 'Unassigned',
                'last_response' => $communication->last_response_at ? $communication->last_response_at->format('Y-m-d H:i:s') : 'No Response',
                'resolution' => $communication->resolution ?? 'N/A'
            ];
        });

        $filename = 'instructor_communications_' . now()->format('Y-m-d_H-i-s') . '.json';
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
    private function exportToExcel($communications)
    {
        // This would require a package like PhpSpreadsheet
        // For now, return CSV format
        return $this->exportToCSV($communications);
    }
} 