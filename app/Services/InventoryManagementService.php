<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\InventoryAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class InventoryManagementService
{
    protected $cacheDuration = 1800; // 30 minutes

    /**
     * Initialize inventory for a course
     */
    public function initializeInventory($courseId, $initialStock = null, $options = [])
    {
        try {
            DB::beginTransaction();

            $course = Course::findOrFail($courseId);
            
            // Check if inventory already exists
            $existingInventory = Inventory::where('course_id', $courseId)->first();
            if ($existingInventory) {
                return [
                    'success' => false,
                    'message' => 'Inventory already exists for this course'
                ];
            }

            // Create inventory record
            $inventory = Inventory::create([
                'course_id' => $courseId,
                'current_stock' => $initialStock ?? $this->calculateInitialStock($course),
                'max_stock' => $options['max_stock'] ?? 1000,
                'min_stock' => $options['min_stock'] ?? 10,
                'reorder_point' => $options['reorder_point'] ?? 20,
                'unit_cost' => $options['unit_cost'] ?? 0,
                'status' => 'active',
                'tracking_enabled' => $options['tracking_enabled'] ?? true,
                'auto_reorder' => $options['auto_reorder'] ?? false,
                'reorder_quantity' => $options['reorder_quantity'] ?? 50
            ]);

            // Create initial transaction
            $this->createInventoryTransaction($inventory, 'initial', $inventory->current_stock, 'Initial inventory setup');

            DB::commit();

            Log::info("Inventory initialized for course", [
                'course_id' => $courseId,
                'inventory_id' => $inventory->id,
                'initial_stock' => $inventory->current_stock
            ]);

            return [
                'success' => true,
                'inventory' => $inventory,
                'message' => 'Inventory initialized successfully'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to initialize inventory: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to initialize inventory: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate initial stock based on course type
     */
    protected function calculateInitialStock($course)
    {
        // For digital courses, stock is typically unlimited
        if ($course->type === 'digital') {
            return 999999;
        }

        // For physical courses or limited access courses
        if ($course->type === 'physical') {
            return 100;
        }

        // For subscription courses
        if ($course->type === 'subscription') {
            return 1000;
        }

        // Default stock
        return 100;
    }

    /**
     * Update inventory stock
     */
    public function updateStock($courseId, $quantity, $type, $notes = null, $metadata = [])
    {
        try {
            DB::beginTransaction();

            $inventory = Inventory::where('course_id', $courseId)->first();
            if (!$inventory) {
                return [
                    'success' => false,
                    'message' => 'Inventory not found for this course'
                ];
            }

            $oldStock = $inventory->current_stock;
            $newStock = $this->calculateNewStock($oldStock, $quantity, $type);

            // Validate stock level
            if ($newStock < 0) {
                return [
                    'success' => false,
                    'message' => 'Insufficient stock for this operation'
                ];
            }

            // Update inventory
            $inventory->update([
                'current_stock' => $newStock,
                'last_updated' => now()
            ]);

            // Create transaction record
            $transaction = $this->createInventoryTransaction($inventory, $type, $quantity, $notes, $metadata);

            // Check for low stock alerts
            $this->checkLowStockAlerts($inventory);

            // Check for out of stock alerts
            $this->checkOutOfStockAlerts($inventory);

            // Auto-reorder if enabled
            if ($inventory->auto_reorder && $newStock <= $inventory->reorder_point) {
                $this->triggerAutoReorder($inventory);
            }

            DB::commit();

            Log::info("Inventory stock updated", [
                'course_id' => $courseId,
                'type' => $type,
                'quantity' => $quantity,
                'old_stock' => $oldStock,
                'new_stock' => $newStock
            ]);

            return [
                'success' => true,
                'inventory' => $inventory,
                'transaction' => $transaction,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'message' => 'Stock updated successfully'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to update stock: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to update stock: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate new stock level
     */
    protected function calculateNewStock($currentStock, $quantity, $type)
    {
        return match($type) {
            'in' => $currentStock + $quantity,
            'out' => $currentStock - $quantity,
            'adjustment' => $quantity, // Direct adjustment
            'reserved' => $currentStock - $quantity, // Reserve stock
            'released' => $currentStock + $quantity, // Release reserved stock
            default => $currentStock
        };
    }

    /**
     * Create inventory transaction
     */
    protected function createInventoryTransaction($inventory, $type, $quantity, $notes = null, $metadata = [])
    {
        return InventoryTransaction::create([
            'inventory_id' => $inventory->id,
            'course_id' => $inventory->course_id,
            'transaction_type' => $type,
            'quantity' => $quantity,
            'stock_before' => $inventory->current_stock - $this->getQuantityChange($type, $quantity),
            'stock_after' => $inventory->current_stock,
            'notes' => $notes,
            'metadata' => $metadata,
            'processed_at' => now()
        ]);
    }

    /**
     * Get quantity change for transaction type
     */
    protected function getQuantityChange($type, $quantity)
    {
        return match($type) {
            'in' => $quantity,
            'out' => -$quantity,
            'adjustment' => 0, // Direct adjustment
            'reserved' => -$quantity,
            'released' => $quantity,
            default => 0
        };
    }

    /**
     * Check for low stock alerts
     */
    protected function checkLowStockAlerts($inventory)
    {
        if ($inventory->current_stock <= $inventory->min_stock && $inventory->current_stock > 0) {
            $this->createInventoryAlert($inventory, 'low_stock', [
                'current_stock' => $inventory->current_stock,
                'min_stock' => $inventory->min_stock
            ]);
        }
    }

    /**
     * Check for out of stock alerts
     */
    protected function checkOutOfStockAlerts($inventory)
    {
        if ($inventory->current_stock <= 0) {
            $this->createInventoryAlert($inventory, 'out_of_stock', [
                'current_stock' => $inventory->current_stock
            ]);
        }
    }

    /**
     * Create inventory alert
     */
    protected function createInventoryAlert($inventory, $type, $data = [])
    {
        // Check if alert already exists
        $existingAlert = InventoryAlert::where('inventory_id', $inventory->id)
            ->where('type', $type)
            ->where('status', 'active')
            ->first();

        if ($existingAlert) {
            return $existingAlert;
        }

        return InventoryAlert::create([
            'inventory_id' => $inventory->id,
            'course_id' => $inventory->course_id,
            'type' => $type,
            'status' => 'active',
            'data' => $data,
            'created_at' => now()
        ]);
    }

    /**
     * Trigger auto-reorder
     */
    protected function triggerAutoReorder($inventory)
    {
        try {
            // Create reorder transaction
            $this->createInventoryTransaction(
                $inventory,
                'reorder',
                $inventory->reorder_quantity,
                'Auto-reorder triggered',
                ['auto_reorder' => true]
            );

            // Send reorder notification
            $this->sendReorderNotification($inventory);

            Log::info("Auto-reorder triggered", [
                'inventory_id' => $inventory->id,
                'course_id' => $inventory->course_id,
                'reorder_quantity' => $inventory->reorder_quantity
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to trigger auto-reorder: " . $e->getMessage());
        }
    }

    /**
     * Send reorder notification
     */
    protected function sendReorderNotification($inventory)
    {
        // This would integrate with notification system
        Log::info("Reorder notification sent", [
            'inventory_id' => $inventory->id,
            'course_id' => $inventory->course_id
        ]);
    }

    /**
     * Reserve stock for order
     */
    public function reserveStock($courseId, $quantity, $orderId = null, $metadata = [])
    {
        return $this->updateStock($courseId, $quantity, 'reserved', 'Stock reserved for order', array_merge($metadata, [
            'order_id' => $orderId,
            'reservation_type' => 'order'
        ]));
    }

    /**
     * Release reserved stock
     */
    public function releaseReservedStock($courseId, $quantity, $orderId = null, $metadata = [])
    {
        return $this->updateStock($courseId, $quantity, 'released', 'Reserved stock released', array_merge($metadata, [
            'order_id' => $orderId,
            'release_type' => 'order_cancellation'
        ]));
    }

    /**
     * Get inventory status
     */
    public function getInventoryStatus($courseId)
    {
        try {
            $inventory = Inventory::with(['course', 'transactions' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(10);
            }])->where('course_id', $courseId)->first();

            if (!$inventory) {
                return [
                    'success' => false,
                    'message' => 'Inventory not found'
                ];
            }

            $status = $this->calculateInventoryStatus($inventory);

            return [
                'success' => true,
                'inventory' => $inventory,
                'status' => $status
            ];

        } catch (\Exception $e) {
            Log::error("Failed to get inventory status: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to get inventory status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate inventory status
     */
    protected function calculateInventoryStatus($inventory)
    {
        $currentStock = $inventory->current_stock;
        $minStock = $inventory->min_stock;
        $reorderPoint = $inventory->reorder_point;

        if ($currentStock <= 0) {
            return 'out_of_stock';
        }

        if ($currentStock <= $minStock) {
            return 'critical';
        }

        if ($currentStock <= $reorderPoint) {
            return 'low';
        }

        if ($currentStock >= $inventory->max_stock * 0.8) {
            return 'high';
        }

        return 'normal';
    }

    /**
     * Get inventory alerts
     */
    public function getInventoryAlerts($filters = [])
    {
        try {
            $query = InventoryAlert::with(['inventory.course']);

            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['course_id'])) {
                $query->where('course_id', $filters['course_id']);
            }

            return $query->orderBy('created_at', 'desc')->paginate($filters['per_page'] ?? 15);

        } catch (\Exception $e) {
            Log::error("Failed to get inventory alerts: " . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get inventory transactions
     */
    public function getInventoryTransactions($courseId, $filters = [])
    {
        try {
            $query = InventoryTransaction::where('course_id', $courseId);

            if (isset($filters['type'])) {
                $query->where('transaction_type', $filters['type']);
            }

            if (isset($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            return $query->orderBy('created_at', 'desc')->paginate($filters['per_page'] ?? 15);

        } catch (\Exception $e) {
            Log::error("Failed to get inventory transactions: " . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get inventory statistics
     */
    public function getInventoryStats($period = 'month')
    {
        try {
            $startDate = $this->getStartDate($period);
            
            $stats = [
                'total_courses' => Inventory::count(),
                'active_inventories' => Inventory::where('status', 'active')->count(),
                'low_stock_courses' => Inventory::where('current_stock', '<=', DB::raw('min_stock'))->count(),
                'out_of_stock_courses' => Inventory::where('current_stock', '<=', 0)->count(),
                'total_transactions' => InventoryTransaction::where('created_at', '>=', $startDate)->count(),
                'stock_in' => InventoryTransaction::where('transaction_type', 'in')
                    ->where('created_at', '>=', $startDate)
                    ->sum('quantity'),
                'stock_out' => InventoryTransaction::where('transaction_type', 'out')
                    ->where('created_at', '>=', $startDate)
                    ->sum('quantity'),
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => now()
            ];

            return $stats;

        } catch (\Exception $e) {
            Log::error("Failed to get inventory stats: " . $e->getMessage());
            return [];
        }
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

    /**
     * Export inventory report
     */
    public function exportInventoryReport($filters = [], $format = 'csv')
    {
        try {
            $inventories = $this->getInventoryDataForExport($filters);

            switch ($format) {
                case 'csv':
                    return $this->exportToCSV($inventories);
                    
                case 'excel':
                    return $this->exportToExcel($inventories);
                    
                case 'pdf':
                    return $this->exportToPDF($inventories);
                    
                default:
                    return [
                        'success' => false,
                        'message' => 'Unsupported export format'
                    ];
            }

        } catch (\Exception $e) {
            Log::error("Export failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get inventory data for export
     */
    protected function getInventoryDataForExport($filters)
    {
        $query = Inventory::with(['course', 'course.instructor']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['course_id'])) {
            $query->where('course_id', $filters['course_id']);
        }

        return $query->get();
    }

    /**
     * Export to CSV
     */
    protected function exportToCSV($inventories)
    {
        // This would generate CSV content
        return [
            'success' => true,
            'message' => 'CSV export requires additional setup',
            'format' => 'csv'
        ];
    }

    /**
     * Export to Excel
     */
    protected function exportToExcel($inventories)
    {
        // This would integrate with Excel generation library
        return [
            'success' => true,
            'message' => 'Excel export requires additional setup',
            'format' => 'excel'
        ];
    }

    /**
     * Export to PDF
     */
    protected function exportToPDF($inventories)
    {
        // This would integrate with PDF generation library
        return [
            'success' => true,
            'message' => 'PDF export requires additional setup',
            'format' => 'pdf'
        ];
    }
} 