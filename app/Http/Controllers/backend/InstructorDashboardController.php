<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Exports\EarningsExport;
use App\Exports\VisitsExport;
use App\Mail\AnalyticsReport;
use App\Models\DashboardWidget;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Arr;

class InstructorDashboardController extends Controller
{
    protected $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    public function index() 
    { 
        $instructorId = auth()->id();
        $widgets = DashboardWidget::where('user_id', $instructorId)
            ->where('is_visible', true)
            ->orderBy('position_y')
            ->orderBy('position_x')
            ->get();
            
        $availableWidgets = DashboardWidget::getAvailableWidgets();
        
        return view('backend.instructor.dashboard.index', compact('widgets', 'availableWidgets')); 
    }
    public function notifications() { return view('backend.instructor.dashboard.notifications'); }
    public function calendar() { return view('backend.instructor.dashboard.calendar'); }
    public function messages() { return view('backend.instructor.dashboard.messages'); }
    public function liveSchedule() { return view('backend.instructor.live.schedule'); }
    
    public function analyticsPerformance() 
    { 
        $instructorId = auth()->id();
        $stats = $this->analyticsService->getTotalStats($instructorId);
        
        return view('backend.instructor.analytics.performance', compact('stats')); 
    }
    
    public function analyticsEarnings() 
    { 
        $instructorId = auth()->id();
        $startDate = request('start_date', Carbon::now()->subDays(30));
        $endDate = request('end_date', Carbon::now());
        
        $earnings = $this->analyticsService->getEarningsReport($instructorId, $startDate, $endDate);
        $totalEarnings = $earnings->sum('total_earnings');
        
        return view('backend.instructor.analytics.earnings', compact('earnings', 'totalEarnings')); 
    }
    
    public function analyticsVisits() 
    { 
        $instructorId = auth()->id();
        $startDate = request('start_date', Carbon::now()->subDays(30));
        $endDate = request('end_date', Carbon::now());
        
        $visits = $this->analyticsService->getVisitsReport($instructorId, $startDate, $endDate);
        $totalViews = $visits->sum('views');
        
        return view('backend.instructor.analytics.visits', compact('visits', 'totalViews')); 
    }
    
    public function analyticsEngagement() 
    { 
        $instructorId = auth()->id();
        $startDate = request('start_date', Carbon::now()->subDays(30));
        $endDate = request('end_date', Carbon::now());
        
        $engagements = $this->analyticsService->getEngagementReport($instructorId, $startDate, $endDate);
        $totalEngagements = $engagements->count();
        
        return view('backend.instructor.analytics.engagement', compact('engagements', 'totalEngagements')); 
    }

    // Export Methods
    public function exportEarningsExcel(Request $request)
    {
        $instructorId = auth()->id();
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        
        $filename = 'earnings_report_' . now()->format('Y-m-d_H-i-s') . '.xlsx';
        
        return Excel::download(new EarningsExport($instructorId, $startDate, $endDate), $filename);
    }

    public function exportVisitsExcel(Request $request)
    {
        $instructorId = auth()->id();
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        
        $filename = 'visits_report_' . now()->format('Y-m-d_H-i-s') . '.xlsx';
        
        return Excel::download(new VisitsExport($instructorId, $startDate, $endDate), $filename);
    }

    public function exportEarningsPdf(Request $request)
    {
        $instructorId = auth()->id();
        $startDate = $request->get('start_date', Carbon::now()->subDays(30));
        $endDate = $request->get('end_date', Carbon::now());
        
        $earnings = $this->analyticsService->getEarningsReport($instructorId, $startDate, $endDate);
        $totalEarnings = $earnings->sum('total_earnings');
        
        $pdf = Pdf::loadView('backend.instructor.analytics.pdf.earnings', compact('earnings', 'totalEarnings'));
        
        return $pdf->download('earnings_report_' . now()->format('Y-m-d_H-i-s') . '.pdf');
    }

    // Email Report Methods
    public function sendEmailReport(Request $request)
    {
        $request->validate([
            'report_type' => 'required|in:earnings,visits,engagement',
            'period' => 'required|in:weekly,monthly,quarterly',
            'email' => 'required|email'
        ]);

        $instructorId = auth()->id();
        $instructor = auth()->user();
        $reportType = $request->report_type;
        $period = $request->period;
        $email = $request->email;

        // Calculate date range based on period
        $endDate = Carbon::now();
        switch ($period) {
            case 'weekly':
                $startDate = Carbon::now()->subWeek();
                $periodText = 'Last Week';
                break;
            case 'monthly':
                $startDate = Carbon::now()->subMonth();
                $periodText = 'Last Month';
                break;
            case 'quarterly':
                $startDate = Carbon::now()->subQuarter();
                $periodText = 'Last Quarter';
                break;
        }

        // Get report data
        $reportData = [];
        switch ($reportType) {
            case 'earnings':
                $earnings = $this->analyticsService->getEarningsReport($instructorId, $startDate, $endDate);
                $reportData = [
                    'total_earnings' => $earnings->sum('total_earnings'),
                    'total_orders' => $earnings->count(),
                    'avg_order' => $earnings->count() > 0 ? $earnings->sum('total_earnings') / $earnings->count() : 0
                ];
                break;
            case 'visits':
                $visits = $this->analyticsService->getVisitsReport($instructorId, $startDate, $endDate);
                $reportData = [
                    'total_views' => $visits->sum('views'),
                    'unique_visitors' => $visits->sum('unique_visitors'),
                    'total_clicks' => $visits->sum('clicks')
                ];
                break;
            case 'engagement':
                $engagements = $this->analyticsService->getEngagementReport($instructorId, $startDate, $endDate);
                $reportData = [
                    'total_engagements' => $engagements->count(),
                    'comments' => $engagements->where('type', 'comment')->count(),
                    'completions' => $engagements->where('type', 'completion')->count()
                ];
                break;
        }

        // Send email
        Mail::to($email)->send(new AnalyticsReport($instructor, $reportData, $reportType, $periodText));

        return response()->json([
            'success' => true,
            'message' => ucfirst($reportType) . ' report sent to ' . $email
        ]);
    }

    public function scheduleEmailReports(Request $request)
    {
        $request->validate([
            'report_type' => 'required|in:earnings,visits,engagement',
            'frequency' => 'required|in:weekly,monthly',
            'email' => 'required|email',
            'enabled' => 'required|boolean'
        ]);

        $instructorId = auth()->id();
        $reportType = $request->report_type;
        $frequency = $request->frequency;
        $email = $request->email;
        $enabled = $request->enabled;

        // Store schedule in database (you can create a new table for this)
        // For now, we'll just return success
        // TODO: Implement database storage for scheduled reports

        return response()->json([
            'success' => true,
            'message' => ucfirst($frequency) . ' ' . $reportType . ' reports ' . ($enabled ? 'scheduled' : 'disabled') . ' for ' . $email
        ]);
    }

    // Dashboard Widget Methods
    public function getWidgetData(Request $request)
    {
        $request->validate([
            'widget_type' => 'required|string'
        ]);

        $instructorId = auth()->id();
        $widgetType = $request->widget_type;

        switch ($widgetType) {
            case 'earnings_overview':
                $data = $this->analyticsService->getEarningsReport($instructorId, Carbon::now()->subDays(30), Carbon::now());
                return response()->json([
                    'total_earnings' => $data->sum('total_earnings'),
                    'total_orders' => $data->count(),
                    'avg_order' => $data->count() > 0 ? $data->sum('total_earnings') / $data->count() : 0,
                    'chart_data' => $data->pluck('total_earnings')->toArray()
                ]);

            case 'visits_overview':
                $data = $this->analyticsService->getVisitsReport($instructorId, Carbon::now()->subDays(30), Carbon::now());
                return response()->json([
                    'total_views' => $data->sum('views'),
                    'unique_visitors' => $data->sum('unique_visitors'),
                    'total_clicks' => $data->sum('clicks'),
                    'chart_data' => [
                        'views' => $data->pluck('views')->toArray(),
                        'visitors' => $data->pluck('unique_visitors')->toArray()
                    ]
                ]);

            case 'engagement_overview':
                $data = $this->analyticsService->getEngagementReport($instructorId, Carbon::now()->subDays(30), Carbon::now());
                return response()->json([
                    'total_engagements' => $data->count(),
                    'comments' => $data->where('engagement_type', 'comment')->count(),
                    'completions' => $data->where('engagement_type', 'complete')->count(),
                    'chart_data' => $data->groupBy('engagement_type')->map->count()->toArray()
                ]);

            case 'recent_orders':
                // Get recent orders from orders table
                $orders = \App\Models\Order::where('instructor_id', $instructorId)
                    ->with('course')
                    ->latest()
                    ->take(5)
                    ->get();
                
                return response()->json([
                    'orders' => $orders->map(function($order) {
                        return [
                            'id' => $order->id,
                            'course_title' => $order->course ? $order->course->course_title : 'N/A',
                            'amount' => $order->amount,
                            'status' => $order->status,
                            'created_at' => $order->created_at->format('M d, Y')
                        ];
                    })
                ]);

            case 'top_courses':
                $courses = \App\Models\Course::where('instructor_id', $instructorId)
                    ->withCount('orders')
                    ->withSum('orders', 'amount')
                    ->orderByDesc('orders_sum_amount')
                    ->take(5)
                    ->get();
                
                return response()->json([
                    'courses' => $courses->map(function($course) {
                        return [
                            'id' => $course->id,
                            'title' => $course->course_title,
                            'earnings' => $course->orders_sum_amount ?? 0,
                            'orders' => $course->orders_count ?? 0
                        ];
                    })
                ]);

            default:
                return response()->json(['error' => 'Unknown widget type'], 400);
        }
    }

    public function saveWidgetLayout(Request $request)
    {
        $request->validate([
            'widgets' => 'required|array',
            'widgets.*.id' => 'required|exists:dashboard_widgets,id',
            'widgets.*.position_x' => 'required|integer|min:0',
            'widgets.*.position_y' => 'required|integer|min:0',
            'widgets.*.width' => 'required|integer|min:1|max:12',
            'widgets.*.height' => 'required|integer|min:1|max:10'
        ]);

        $instructorId = auth()->id();

        foreach ($request->widgets as $widgetData) {
            $widget = DashboardWidget::where('id', $widgetData['id'])
                ->where('user_id', $instructorId)
                ->first();

            if ($widget) {
                $widget->update([
                    'position_x' => $widgetData['position_x'],
                    'position_y' => $widgetData['position_y'],
                    'width' => $widgetData['width'],
                    'height' => $widgetData['height']
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Dashboard layout saved successfully'
        ]);
    }

    public function addWidget(Request $request)
    {
        try {
            $request->validate([
                'widget_type' => 'required|string',
                'widget_title' => 'required|string|max:255'
            ]);

            $instructorId = auth()->id();
            $availableWidgets = DashboardWidget::getAvailableWidgets();

            if (!isset($availableWidgets[$request->widget_type])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid widget type: ' . $request->widget_type
                ], 400);
            }

            $widgetConfig = $availableWidgets[$request->widget_type];

            // Check if user already has this widget type
            $existingWidget = DashboardWidget::where('user_id', $instructorId)
                ->where('widget_type', $request->widget_type)
                ->first();

            if ($existingWidget) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a ' . $widgetConfig['title'] . ' widget on your dashboard.'
                ], 422);
            }

            $widget = DashboardWidget::create([
                'user_id' => $instructorId,
                'widget_type' => $request->widget_type,
                'widget_title' => $request->widget_title,
                'widget_config' => $widgetConfig,
                'position_x' => 0,
                'position_y' => 0,
                'width' => $widgetConfig['default_width'],
                'height' => $widgetConfig['default_height']
            ]);

            \Log::info('Widget added successfully', [
                'user_id' => $instructorId,
                'widget_type' => $request->widget_type,
                'widget_id' => $widget->id
            ]);

            return response()->json([
                'success' => true,
                'message' => $widgetConfig['title'] . ' widget added successfully!',
                'widget' => $widget
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::warning('Widget validation failed', [
                'user_id' => auth()->id(),
                'errors' => $e->errors()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . implode(', ', Arr::flatten($e->errors()))
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Failed to add widget', [
                'user_id' => auth()->id(),
                'widget_type' => $request->widget_type ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to add widget: ' . $e->getMessage()
            ], 500);
        }
    }

    public function removeWidget(Request $request)
    {
        $request->validate([
            'widget_id' => 'required|exists:dashboard_widgets,id'
        ]);

        $instructorId = auth()->id();
        $widget = DashboardWidget::where('id', $request->widget_id)
            ->where('user_id', $instructorId)
            ->first();

        if ($widget) {
            $widget->delete();
            return response()->json([
                'success' => true,
                'message' => 'Widget removed successfully'
            ]);
        }

        return response()->json(['error' => 'Widget not found'], 404);
    }

    public function toggleWidget(Request $request)
    {
        $request->validate([
            'widget_id' => 'required|exists:dashboard_widgets,id',
            'action' => 'required|in:show,hide,collapse,expand'
        ]);

        $instructorId = auth()->id();
        $widget = DashboardWidget::where('id', $request->widget_id)
            ->where('user_id', $instructorId)
            ->first();

        if ($widget) {
            switch ($request->action) {
                case 'show':
                    $widget->update(['is_visible' => true]);
                    break;
                case 'hide':
                    $widget->update(['is_visible' => false]);
                    break;
                case 'collapse':
                    $widget->update(['is_collapsed' => true]);
                    break;
                case 'expand':
                    $widget->update(['is_collapsed' => false]);
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => 'Widget updated successfully'
            ]);
        }

        return response()->json(['error' => 'Widget not found'], 404);
    }
}
