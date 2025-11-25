<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'order_id',
        'user_id',
        'instructor_id',
        'course_id',
        'subtotal',
        'tax_amount',
        'tax_rate',
        'tax_type',
        'total_amount',
        'currency',
        'status',
        'due_date',
        'sent_at',
        'paid_at',
        'notes',
        'pdf_path',
        'metadata'
    ];

    protected $casts = [
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'metadata' => 'array'
    ];

    /**
     * Get the order that owns the invoice.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user that owns the invoice.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the instructor that owns the invoice.
     */
    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * Get the course that owns the invoice.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Scope to get invoices by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get overdue invoices.
     */
    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                    ->where('status', '!=', 'paid');
    }

    /**
     * Scope to get invoices due soon.
     */
    public function scopeDueSoon($query, $days = 7)
    {
        return $query->where('due_date', '<=', now()->addDays($days))
                    ->where('due_date', '>', now())
                    ->where('status', '!=', 'paid');
    }

    /**
     * Check if invoice is overdue.
     */
    public function isOverdue()
    {
        return $this->due_date < now() && $this->status !== 'paid';
    }

    /**
     * Check if invoice is due soon.
     */
    public function isDueSoon($days = 7)
    {
        return $this->due_date <= now()->addDays($days) 
               && $this->due_date > now() 
               && $this->status !== 'paid';
    }

    /**
     * Check if invoice is paid.
     */
    public function isPaid()
    {
        return $this->status === 'paid';
    }

    /**
     * Check if invoice is pending.
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if invoice is overdue.
     */
    public function isOverdue()
    {
        return $this->due_date < now() && $this->status !== 'paid';
    }

    /**
     * Get formatted total amount.
     */
    public function getFormattedTotalAttribute()
    {
        return number_format($this->total_amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get formatted tax amount.
     */
    public function getFormattedTaxAmountAttribute()
    {
        return number_format($this->tax_amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get formatted subtotal.
     */
    public function getFormattedSubtotalAttribute()
    {
        return number_format($this->subtotal, 2) . ' ' . $this->currency;
    }

    /**
     * Get formatted tax rate.
     */
    public function getFormattedTaxRateAttribute()
    {
        return number_format($this->tax_rate * 100, 2) . '%';
    }

    /**
     * Get days until due.
     */
    public function getDaysUntilDueAttribute()
    {
        if ($this->isPaid()) {
            return 0;
        }

        $days = now()->diffInDays($this->due_date, false);
        return $days;
    }

    /**
     * Get overdue days.
     */
    public function getOverdueDaysAttribute()
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return now()->diffInDays($this->due_date);
    }

    /**
     * Get status badge class.
     */
    public function getStatusBadgeClassAttribute()
    {
        return match($this->status) {
            'paid' => 'badge-success',
            'pending' => 'badge-warning',
            'overdue' => 'badge-danger',
            'cancelled' => 'badge-secondary',
            'refunded' => 'badge-info',
            default => 'badge-primary'
        };
    }

    /**
     * Get status display name.
     */
    public function getStatusDisplayNameAttribute()
    {
        return match($this->status) {
            'paid' => 'Paid',
            'pending' => 'Pending',
            'overdue' => 'Overdue',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            'issued' => 'Issued',
            default => ucfirst($this->status)
        };
    }

    /**
     * Mark invoice as paid.
     */
    public function markAsPaid()
    {
        $this->update([
            'status' => 'paid',
            'paid_at' => now()
        ]);
    }

    /**
     * Mark invoice as sent.
     */
    public function markAsSent()
    {
        $this->update(['sent_at' => now()]);
    }

    /**
     * Get PDF download URL.
     */
    public function getPdfDownloadUrlAttribute()
    {
        if ($this->pdf_path) {
            return Storage::url($this->pdf_path);
        }
        return null;
    }

    /**
     * Check if PDF exists.
     */
    public function hasPdf()
    {
        return !empty($this->pdf_path) && Storage::exists($this->pdf_path);
    }

    /**
     * Get invoice items for display.
     */
    public function getInvoiceItemsAttribute()
    {
        return [
            [
                'name' => $this->course->title ?? 'Course',
                'description' => $this->course->description ?? '',
                'quantity' => 1,
                'unit_price' => $this->subtotal,
                'total' => $this->subtotal
            ]
        ];
    }

    /**
     * Get company information from metadata.
     */
    public function getCompanyInfoAttribute()
    {
        return $this->metadata['company_info'] ?? [];
    }

    /**
     * Get tax information from metadata.
     */
    public function getTaxInfoAttribute()
    {
        return $this->metadata['tax_info'] ?? [];
    }
} 