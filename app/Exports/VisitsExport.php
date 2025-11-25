<?php

namespace App\Exports;

use App\Models\CourseAnalytics;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VisitsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
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
        $query = CourseAnalytics::where('instructor_id', $this->instructorId)
            ->with(['course']);

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
            'Views',
            'Unique Visitors',
            'Clicks',
            'Avg Watch Time (min)'
        ];
    }

    public function map($visit): array
    {
        return [
            $visit->date->format('M d, Y'),
            $visit->course ? $visit->course->course_title : 'N/A',
            number_format($visit->views),
            number_format($visit->unique_visitors),
            number_format($visit->clicks),
            round($visit->avg_watch_time / 60, 2)
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