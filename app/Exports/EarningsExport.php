<?php

namespace App\Exports;

use App\Models\EarningsAnalytics;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EarningsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $instructorId;
    protected $startDate;
    protected $endDate;

    public function __construct($instructorId, $startDate = null, $endDate = null)
    {
        $this->instructorId = $instructorId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function collection()
    {
        $query = EarningsAnalytics::where('instructor_id', $this->instructorId)
            ->with(['course', 'instructor']);

        if ($this->startDate) {
            $query->where('date', '>=', $this->startDate);
        }

        if ($this->endDate) {
            $query->where('date', '<=', $this->endDate);
        }

        return $query->orderBy('date')->get();
    }

    public function headings(): array
    {
        return [
            'Date',
            'Course Title',
            'Earnings ($)',
            'Orders',
            'Payment Type',
            'Currency'
        ];
    }

    public function map($earning): array
    {
        return [
            $earning->date->format('M d, Y'),
            $earning->course ? $earning->course->course_title : 'N/A',
            number_format($earning->total_earnings, 2),
            $earning->order_count,
            ucfirst($earning->payment_type ?? 'N/A'),
            $earning->currency
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2E8F0']
                ]
            ]
        ];
    }
}
