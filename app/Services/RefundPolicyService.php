<?php

namespace App\Services;

use App\Models\RefundPolicy;
use App\Models\RefundRequest;
use App\Models\Order;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RefundPolicyService
{
    protected $defaultRefundPolicy = [
        'digital_products' => [
            'refund_allowed' => true,
            'refund_period_hours' => 24,
            'refund_percentage' => 100,
            'conditions' => [
                'not_completed' => true,
                'not_downloaded' => true,
                'not_accessed' => true
            ]
        ],
        'subscriptions' => [
            'refund_allowed' => true,
            'refund_period_hours' => 48,
            'refund_percentage' => 100,
            'conditions' => [
                'not_used' => true,
                'within_period' => true
            ]
        ],
        'bundles' => [
            'refund_allowed' => true,
            'refund_period_hours' => 72,
            'refund_percentage' => 100,
            'conditions' => [
                'not_completed' => true,
                'not_accessed' => true
            ]
        ]
    ];

    /**
     * Check if refund is allowed for an order
     */
    public function isRefundAllowed($orderId, $userId = null)
    {
        try {
            $order = Order::with(['course', 'user'])->findOrFail($orderId);
            
            // Check if user owns the order
            if ($userId && $order->user_id !== $userId) {
                return [
                    'allowed' => false,
                    'reason' => 'Order does not belong to user'
                ];
            }

            // Get refund policy for course type
            $policy = $this->getRefundPolicy($order->course);
            
            if (!$policy['refund_allowed']) {
                return [
                    'allowed' => false,
                    'reason' => 'Refunds not allowed for this course type'
                ];
            }

            // Check refund period
            $orderAge = $order->created_at->diffInHours(now());
            if ($orderAge > $policy['refund_period_hours']) {
                return [
                    'allowed' => false,
                    'reason' => "Refund period expired ({$policy['refund_period_hours']} hours)"
                ];
            }

            // Check conditions
            $conditionCheck = $this->checkRefundConditions($order, $policy['conditions']);
            if (!$conditionCheck['met']) {
                return [
                    'allowed' => false,
                    'reason' => $conditionCheck['reason']
                ];
            }

            return [
                'allowed' => true,
                'policy' => $policy,
                'refund_amount' => $this->calculateRefundAmount($order, $policy),
                'reason' => 'Refund allowed'
            ];

        } catch (\Exception $e) {
            Log::error("Refund check error: " . $e->getMessage());
            
            return [
                'allowed' => false,
                'reason' => 'Error checking refund eligibility'
            ];
        }
    }

    /**
     * Get refund policy for a course
     */
    public function getRefundPolicy($course)
    {
        // Check if course has custom refund policy
        if ($course->refund_policy) {
            return $course->refund_policy;
        }

        // Check if instructor has custom refund policy
        if ($course->instructor && $course->instructor->refund_policy) {
            return $course->instructor->refund_policy;
        }

        // Return default policy based on course type
        $courseType = $this->getCourseType($course);
        return $this->defaultRefundPolicy[$courseType] ?? $this->defaultRefundPolicy['digital_products'];
    }

    /**
     * Check refund conditions
     */
    protected function checkRefundConditions($order, $conditions)
    {
        foreach ($conditions as $condition => $required) {
            if (!$required) continue;

            switch ($condition) {
                case 'not_completed':
                    if ($this->isCourseCompleted($order)) {
                        return [
                            'met' => false,
                            'reason' => 'Course already completed'
                        ];
                    }
                    break;

                case 'not_downloaded':
                    if ($this->hasDownloads($order)) {
                        return [
                            'met' => false,
                            'reason' => 'Course materials already downloaded'
                        ];
                    }
                    break;

                case 'not_accessed':
                    if ($this->hasCourseAccess($order)) {
                        return [
                            'met' => false,
                            'reason' => 'Course already accessed'
                        ];
                    }
                    break;

                case 'not_used':
                    if ($this->hasSubscriptionUsage($order)) {
                        return [
                            'met' => false,
                            'reason' => 'Subscription already used'
                        ];
                    }
                    break;

                case 'within_period':
                    // Already checked in isRefundAllowed
                    break;
            }
        }

        return ['met' => true, 'reason' => 'All conditions met'];
    }

    /**
     * Calculate refund amount
     */
    protected function calculateRefundAmount($order, $policy)
    {
        $baseAmount = $order->price;
        $refundPercentage = $policy['refund_percentage'] / 100;
        
        return round($baseAmount * $refundPercentage, 2);
    }

    /**
     * Get course type
     */
    protected function getCourseType($course)
    {
        if ($course->type === 'subscription') {
            return 'subscriptions';
        }
        
        if ($course->is_bundle) {
            return 'bundles';
        }
        
        return 'digital_products';
    }

    /**
     * Check if course is completed
     */
    protected function isCourseCompleted($order)
    {
        // Check course progress
        $progress = $order->user->courseProgress()
            ->where('course_id', $order->course_id)
            ->first();
            
        return $progress && $progress->completion_percentage >= 100;
    }

    /**
     * Check if course has downloads
     */
    protected function hasDownloads($order)
    {
        // Check download history
        return $order->user->downloads()
            ->where('course_id', $order->course_id)
            ->exists();
    }

    /**
     * Check if course has been accessed
     */
    protected function hasCourseAccess($order)
    {
        // Check course access logs
        return $order->user->courseAccessLogs()
            ->where('course_id', $order->course_id)
            ->exists();
    }

    /**
     * Check if subscription has been used
     */
    protected function hasSubscriptionUsage($order)
    {
        // Check subscription usage
        return $order->user->subscriptionUsage()
            ->where('course_id', $order->course_id)
            ->exists();
    }

    /**
     * Create refund request
     */
    public function createRefundRequest($orderId, $userId, $reason, $details = null)
    {
        try {
            DB::beginTransaction();

            // Check if refund is allowed
            $refundCheck = $this->isRefundAllowed($orderId, $userId);
            if (!$refundCheck['allowed']) {
                return [
                    'success' => false,
                    'message' => $refundCheck['reason']
                ];
            }

            $order = Order::with(['course', 'user'])->findOrFail($orderId);
            
            // Check if refund request already exists
            $existingRequest = RefundRequest::where('order_id', $orderId)
                ->where('status', '!=', 'rejected')
                ->first();
                
            if ($existingRequest) {
                return [
                    'success' => false,
                    'message' => 'Refund request already exists'
                ];
            }

            // Create refund request
            $refundRequest = RefundRequest::create([
                'order_id' => $orderId,
                'user_id' => $userId,
                'course_id' => $order->course_id,
                'instructor_id' => $order->instructor_id,
                'amount' => $refundCheck['refund_amount'],
                'reason' => $reason,
                'details' => $details,
                'status' => 'pending',
                'refund_policy' => $refundCheck['policy'],
                'requested_at' => now()
            ]);

            // Notify instructor
            $this->notifyInstructor($refundRequest);

            DB::commit();

            Log::info("Refund request created", [
                'refund_request_id' => $refundRequest->id,
                'order_id' => $orderId,
                'user_id' => $userId,
                'amount' => $refundCheck['refund_amount']
            ]);

            return [
                'success' => true,
                'refund_request' => $refundRequest,
                'message' => 'Refund request created successfully'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create refund request: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to create refund request: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process refund request
     */
    public function processRefundRequest($refundRequestId, $action, $adminId, $notes = null)
    {
        try {
            DB::beginTransaction();

            $refundRequest = RefundRequest::findOrFail($refundRequestId);
            
            if ($refundRequest->status !== 'pending') {
                return [
                    'success' => false,
                    'message' => 'Refund request is not pending'
                ];
            }

            switch ($action) {
                case 'approve':
                    $result = $this->approveRefund($refundRequest, $adminId, $notes);
                    break;
                    
                case 'reject':
                    $result = $this->rejectRefund($refundRequest, $adminId, $notes);
                    break;
                    
                default:
                    return [
                        'success' => false,
                        'message' => 'Invalid action'
                    ];
            }

            if ($result['success']) {
                DB::commit();
                return $result;
            } else {
                DB::rollBack();
                return $result;
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to process refund request: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to process refund request: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Approve refund request
     */
    protected function approveRefund($refundRequest, $adminId, $notes = null)
    {
        try {
            // Update refund request status
            $refundRequest->update([
                'status' => 'approved',
                'approved_by' => $adminId,
                'approved_at' => now(),
                'admin_notes' => $notes
            ]);

            // Process actual refund
            $refundResult = $this->processRefund($refundRequest);
            
            if (!$refundResult['success']) {
                return $refundResult;
            }

            // Notify user
            $this->notifyUser($refundRequest, 'approved');

            return [
                'success' => true,
                'message' => 'Refund approved and processed successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to approve refund: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to approve refund: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Reject refund request
     */
    protected function rejectRefund($refundRequest, $adminId, $notes = null)
    {
        try {
            $refundRequest->update([
                'status' => 'rejected',
                'rejected_by' => $adminId,
                'rejected_at' => now(),
                'admin_notes' => $notes
            ]);

            // Notify user
            $this->notifyUser($refundRequest, 'rejected');

            return [
                'success' => true,
                'message' => 'Refund request rejected'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to reject refund: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to reject refund: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process actual refund
     */
    protected function processRefund($refundRequest)
    {
        try {
            $order = $refundRequest->order;
            $payment = $order->payment;

            // Process refund based on payment method
            switch ($payment->payment_type) {
                case 'stripe':
                    return $this->processStripeRefund($refundRequest);
                    
                case 'paypal':
                    return $this->processPayPalRefund($refundRequest);
                    
                default:
                    return [
                        'success' => false,
                        'message' => 'Unsupported payment method for refund'
                    ];
            }

        } catch (\Exception $e) {
            Log::error("Failed to process refund: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to process refund: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process Stripe refund
     */
    protected function processStripeRefund($refundRequest)
    {
        // This would integrate with Stripe API
        // For now, just mark as processed
        $refundRequest->update([
            'refunded_at' => now(),
            'refund_transaction_id' => 'STRIPE_' . uniqid()
        ]);

        return ['success' => true];
    }

    /**
     * Process PayPal refund
     */
    protected function processPayPalRefund($refundRequest)
    {
        // This would integrate with PayPal API
        // For now, just mark as processed
        $refundRequest->update([
            'refunded_at' => now(),
            'refund_transaction_id' => 'PAYPAL_' . uniqid()
        ]);

        return ['success' => true];
    }

    /**
     * Notify instructor about refund request
     */
    protected function notifyInstructor($refundRequest)
    {
        // Send notification to instructor
        // This would integrate with notification system
        Log::info("Instructor notified about refund request", [
            'refund_request_id' => $refundRequest->id,
            'instructor_id' => $refundRequest->instructor_id
        ]);
    }

    /**
     * Notify user about refund status
     */
    protected function notifyUser($refundRequest, $status)
    {
        // Send notification to user
        // This would integrate with notification system
        Log::info("User notified about refund status", [
            'refund_request_id' => $refundRequest->id,
            'user_id' => $refundRequest->user_id,
            'status' => $status
        ]);
    }

    /**
     * Get refund statistics
     */
    public function getRefundStats($period = 'month', $instructorId = null)
    {
        $startDate = $this->getStartDate($period);
        
        $query = RefundRequest::where('created_at', '>=', $startDate);
        
        if ($instructorId) {
            $query->where('instructor_id', $instructorId);
        }

        $stats = [
            'total_requests' => $query->count(),
            'pending_requests' => $query->where('status', 'pending')->count(),
            'approved_requests' => $query->where('status', 'approved')->count(),
            'rejected_requests' => $query->where('status', 'rejected')->count(),
            'total_refunded_amount' => $query->where('status', 'approved')->sum('amount'),
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => now()
        ];

        return $stats;
    }

    /**
     * Get start date based on period
     */
    protected function getStartDate($period)
    {
        return match($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth()
        };
    }
} 