<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InvoiceService
{
    protected $taxService;
    protected $companyInfo;

    public function __construct(TaxCalculationService $taxService)
    {
        $this->taxService = $taxService;
        $this->companyInfo = $this->getCompanyInfo();
    }

    /**
     * Generate invoice for an order
     */
    public function generateInvoice($orderId, $options = [])
    {
        try {
            DB::beginTransaction();

            $order = Order::with(['user', 'course', 'payment'])->findOrFail($orderId);
            
            // Check if invoice already exists
            $existingInvoice = Invoice::where('order_id', $orderId)->first();
            if ($existingInvoice) {
                return [
                    'success' => true,
                    'invoice' => $existingInvoice,
                    'message' => 'Invoice already exists'
                ];
            }

            // Calculate tax
            $taxInfo = $this->taxService->calculateTax(
                $order->price,
                $order->user->country_code ?? 'TR',
                $order->user->state_code ?? null,
                $options
            );

            // Create invoice
            $invoice = Invoice::create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'order_id' => $orderId,
                'user_id' => $order->user_id,
                'instructor_id' => $order->instructor_id,
                'course_id' => $order->course_id,
                'subtotal' => $order->price,
                'tax_amount' => $taxInfo['tax_amount'],
                'tax_rate' => $taxInfo['tax_rate'],
                'tax_type' => $taxInfo['tax_type'],
                'total_amount' => $taxInfo['total_amount'],
                'currency' => $order->payment->currency ?? 'TRY',
                'status' => 'issued',
                'due_date' => now()->addDays(30),
                'notes' => $options['notes'] ?? null,
                'metadata' => [
                    'tax_info' => $taxInfo,
                    'company_info' => $this->companyInfo,
                    'generated_at' => now()->toISOString()
                ]
            ]);

            // Generate PDF
            $pdfPath = $this->generateInvoicePDF($invoice);
            if ($pdfPath) {
                $invoice->update(['pdf_path' => $pdfPath]);
            }

            DB::commit();

            Log::info("Invoice generated successfully", [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'order_id' => $orderId
            ]);

            return [
                'success' => true,
                'invoice' => $invoice,
                'message' => 'Invoice generated successfully'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to generate invoice: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to generate invoice: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate invoice PDF
     */
    protected function generateInvoicePDF(Invoice $invoice)
    {
        try {
            $invoiceData = $this->prepareInvoiceData($invoice);
            
            // Generate PDF using a template
            $pdfContent = $this->renderInvoiceTemplate($invoiceData);
            
            // Save PDF to storage
            $filename = "invoice_{$invoice->invoice_number}.pdf";
            $path = "invoices/{$filename}";
            
            Storage::put("public/{$path}", $pdfContent);
            
            return $path;

        } catch (\Exception $e) {
            Log::error("Failed to generate invoice PDF: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Prepare invoice data for template
     */
    protected function prepareInvoiceData(Invoice $invoice)
    {
        $order = $invoice->order;
        $user = $invoice->user;
        $course = $invoice->course;
        $payment = $order->payment;

        return [
            'invoice' => $invoice,
            'order' => $order,
            'user' => $user,
            'course' => $course,
            'payment' => $payment,
            'company' => $this->companyInfo,
            'items' => [
                [
                    'name' => $course->title,
                    'description' => $course->description,
                    'quantity' => 1,
                    'unit_price' => $order->price,
                    'total' => $order->price
                ]
            ],
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'due_date' => $invoice->due_date->format('Y-m-d')
        ];
    }

    /**
     * Render invoice template
     */
    protected function renderInvoiceTemplate($data)
    {
        // This is a simplified template rendering
        // In production, you would use a proper PDF library like Dompdf or Snappy
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>Invoice {$data['invoice']->invoice_number}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                .header { text-align: center; margin-bottom: 30px; }
                .company-info { margin-bottom: 30px; }
                .invoice-details { margin-bottom: 30px; }
                .customer-info { margin-bottom: 30px; }
                .items-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                .items-table th, .items-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .items-table th { background-color: #f2f2f2; }
                .totals { text-align: right; }
                .footer { margin-top: 50px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>INVOICE</h1>
                <h2>{$data['company']['name']}</h2>
            </div>
            
            <div class='company-info'>
                <strong>{$data['company']['name']}</strong><br>
                {$data['company']['address']}<br>
                {$data['company']['city']}, {$data['company']['state']} {$data['company']['zip']}<br>
                Phone: {$data['company']['phone']}<br>
                Email: {$data['company']['email']}
            </div>
            
            <div class='invoice-details'>
                <strong>Invoice Number:</strong> {$data['invoice']->invoice_number}<br>
                <strong>Date:</strong> {$data['generated_at']}<br>
                <strong>Due Date:</strong> {$data['due_date']}<br>
                <strong>Status:</strong> {$data['invoice']->status}
            </div>
            
            <div class='customer-info'>
                <strong>Bill To:</strong><br>
                {$data['user']->name}<br>
                {$data['user']->email}<br>
                " . ($data['user']->phone ?? '') . "
            </div>
            
            <table class='items-table'>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>";
                
        foreach ($data['items'] as $item) {
            $html .= "
                <tr>
                    <td>{$item['name']}</td>
                    <td>{$item['quantity']}</td>
                    <td>\${$item['unit_price']}</td>
                    <td>\${$item['total']}</td>
                </tr>";
        }
        
        $html .= "
                </tbody>
            </table>
            
            <div class='totals'>
                <strong>Subtotal:</strong> \${$data['invoice']->subtotal}<br>
                <strong>Tax ({$data['invoice']->tax_type}):</strong> \${$data['invoice']->tax_amount}<br>
                <strong>Total:</strong> \${$data['invoice']->total_amount}
            </div>
            
            <div class='footer'>
                <p>Thank you for your business!</p>
                <p>This is a computer-generated invoice. No signature required.</p>
            </div>
        </body>
        </html>";
        
        return $html;
    }

    /**
     * Generate unique invoice number
     */
    protected function generateInvoiceNumber()
    {
        do {
            $number = 'INV-' . date('Y') . '-' . strtoupper(Str::random(6));
        } while (Invoice::where('invoice_number', $number)->exists());

        return $number;
    }

    /**
     * Get company information
     */
    protected function getCompanyInfo()
    {
        return [
            'name' => config('app.company_name', 'LMS Platform'),
            'address' => config('app.company_address', '123 Business Street'),
            'city' => config('app.company_city', 'Business City'),
            'state' => config('app.company_state', 'BS'),
            'zip' => config('app.company_zip', '12345'),
            'phone' => config('app.company_phone', '+1 (555) 123-4567'),
            'email' => config('app.company_email', 'billing@lmsplatform.com'),
            'website' => config('app.company_website', 'https://lmsplatform.com'),
            'tax_id' => config('app.company_tax_id', '12-3456789')
        ];
    }

    /**
     * Send invoice to customer
     */
    public function sendInvoice($invoiceId, $options = [])
    {
        try {
            $invoice = Invoice::with(['user', 'order'])->findOrFail($invoiceId);
            
            // Send email with invoice attachment
            $emailData = [
                'invoice' => $invoice,
                'user' => $invoice->user,
                'subject' => $options['subject'] ?? "Invoice #{$invoice->invoice_number}",
                'message' => $options['message'] ?? "Please find attached invoice #{$invoice->invoice_number}."
            ];
            
            // Here you would send the email with PDF attachment
            // For now, just log the action
            Log::info("Invoice sent to customer", [
                'invoice_id' => $invoice->id,
                'user_id' => $invoice->user_id,
                'email' => $invoice->user->email
            ]);
            
            // Update invoice status
            $invoice->update(['sent_at' => now()]);
            
            return [
                'success' => true,
                'message' => 'Invoice sent successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to send invoice: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to send invoice: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get invoice by number
     */
    public function getInvoiceByNumber($invoiceNumber)
    {
        return Invoice::with(['user', 'order', 'course'])->where('invoice_number', $invoiceNumber)->first();
    }

    /**
     * Get user invoices
     */
    public function getUserInvoices($userId, $filters = [])
    {
        $query = Invoice::with(['order', 'course'])
            ->where('user_id', $userId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get instructor invoices
     */
    public function getInstructorInvoices($instructorId, $filters = [])
    {
        $query = Invoice::with(['user', 'order', 'course'])
            ->where('instructor_id', $instructorId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Update invoice status
     */
    public function updateInvoiceStatus($invoiceId, $status, $notes = null)
    {
        try {
            $invoice = Invoice::findOrFail($invoiceId);
            
            $invoice->update([
                'status' => $status,
                'notes' => $notes ? $invoice->notes . "\n" . $notes : $invoice->notes
            ]);

            Log::info("Invoice status updated", [
                'invoice_id' => $invoice->id,
                'old_status' => $invoice->getOriginal('status'),
                'new_status' => $status
            ]);

            return [
                'success' => true,
                'invoice' => $invoice,
                'message' => 'Invoice status updated successfully'
            ];

        } catch (\Exception $e) {
            Log::error("Failed to update invoice status: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to update invoice status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get invoice statistics
     */
    public function getInvoiceStats($period = 'month', $instructorId = null)
    {
        $startDate = $this->getStartDate($period);
        
        $query = Invoice::where('created_at', '>=', $startDate);
        
        if ($instructorId) {
            $query->where('instructor_id', $instructorId);
        }

        $stats = [
            'total_invoices' => $query->count(),
            'total_amount' => $query->sum('total_amount'),
            'paid_amount' => $query->where('status', 'paid')->sum('total_amount'),
            'pending_amount' => $query->where('status', 'pending')->sum('total_amount'),
            'overdue_amount' => $query->where('status', 'overdue')->sum('total_amount'),
            'tax_collected' => $query->sum('tax_amount'),
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