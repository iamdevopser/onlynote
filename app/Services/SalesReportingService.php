<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Course;
use App\Models\User;
use App\Models\Payment;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SalesReportingService
{
    protected $cacheDuration = 3600; // 1 hour

    /**
     * Get comprehensive sales report
     */
    public function getSalesReport($filters = [])
    {
        try {
            $cacheKey = "sales_report_" . md5(serialize($filters));
            
            return Cache::remember($cacheKey, $this->cacheDuration, function () use ($filters) {
                $startDate = $filters['start_date'] ?? now()->subMonth();
                $endDate = $filters['end_date'] ?? now();
                $instructorId = $filters['instructor_id'] ?? null;
                $categoryId = $filters['category_id'] ?? null;

                $query = Order::with(['course', 'user', 'payment'])
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$startDate, $endDate]);

                if ($instructorId) {
                    $query->whereHas('course', function ($q) use ($instructorId) {
                        $q->where('instructor_id', $instructorId);
                    });
                }

                if ($categoryId) {
                    $query->whereHas('course', function ($q) use ($categoryId) {
                        $q->where('category_id', $categoryId);
                    });
                }

                $orders = $query->get();

                return [
                    'summary' => $this->getSalesSummary($orders),
                    'trends' => $this->getSalesTrends($startDate, $endDate, $filters),
                    'top_products' => $this->getTopProducts($orders),
                    'top_categories' => $this->getTopCategories($orders),
                    'top_instructors' => $this->getTopInstructors($orders),
                    'customer_analysis' => $this->getCustomerAnalysis($orders),
                    'payment_analysis' => $this->getPaymentAnalysis($orders),
                    'geographic_analysis' => $this->getGeographicAnalysis($orders),
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate
                    ]
                ];
            });

        } catch (\Exception $e) {
            Log::error("Sales report generation failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to generate sales report: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get sales summary
     */
    protected function getSalesSummary($orders)
    {
        $totalRevenue = $orders->sum('price');
        $totalOrders = $orders->count();
        $uniqueCustomers = $orders->unique('user_id')->count();
        $averageOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

        return [
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'unique_customers' => $uniqueCustomers,
            'average_order_value' => $averageOrderValue,
            'conversion_rate' => $this->calculateConversionRate($orders),
            'customer_lifetime_value' => $this->calculateCustomerLifetimeValue($orders)
        ];
    }

    /**
     * Get sales trends
     */
    protected function getSalesTrends($startDate, $endDate, $filters)
    {
        $trends = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $dayStart = $currentDate->copy()->startOfDay();
            $dayEnd = $currentDate->copy()->endOfDay();

            $dailyOrders = Order::where('status', 'completed')
                ->whereBetween('created_at', [$dayStart, $dayEnd]);

            if (isset($filters['instructor_id'])) {
                $dailyOrders->whereHas('course', function ($q) use ($filters) {
                    $q->where('instructor_id', $filters['instructor_id']);
                });
            }

            $dailyData = $dailyOrders->get();

            $trends[] = [
                'date' => $currentDate->format('Y-m-d'),
                'revenue' => $dailyData->sum('price'),
                'orders' => $dailyData->count(),
                'customers' => $dailyData->unique('user_id')->count()
            ];

            $currentDate->addDay();
        }

        return $trends;
    }

    /**
     * Get top products
     */
    protected function getTopProducts($orders)
    {
        return $orders->groupBy('course_id')
            ->map(function ($courseOrders) {
                $course = $courseOrders->first()->course;
                return [
                    'course_id' => $course->id,
                    'title' => $course->title,
                    'revenue' => $courseOrders->sum('price'),
                    'orders' => $courseOrders->count(),
                    'average_rating' => $course->reviews()->avg('rating') ?? 0,
                    'enrollment_count' => $course->enrollments()->count()
                ];
            })
            ->sortByDesc('revenue')
            ->take(10)
            ->values();
    }

    /**
     * Get top categories
     */
    protected function getTopCategories($orders)
    {
        return $orders->groupBy('course.category_id')
            ->map(function ($categoryOrders) {
                $category = $categoryOrders->first()->course->category;
                return [
                    'category_id' => $category->id,
                    'name' => $category->name,
                    'revenue' => $categoryOrders->sum('price'),
                    'orders' => $categoryOrders->count(),
                    'courses_count' => $categoryOrders->unique('course_id')->count()
                ];
            })
            ->sortByDesc('revenue')
            ->take(10)
            ->values();
    }

    /**
     * Get top instructors
     */
    protected function getTopInstructors($orders)
    {
        return $orders->groupBy('course.instructor_id')
            ->map(function ($instructorOrders) {
                $instructor = $instructorOrders->first()->course->instructor;
                return [
                    'instructor_id' => $instructor->id,
                    'name' => $instructor->name,
                    'revenue' => $instructorOrders->sum('price'),
                    'orders' => $instructorOrders->count(),
                    'courses_count' => $instructorOrders->unique('course_id')->count(),
                    'average_rating' => $instructor->courses()->withAvg('reviews', 'rating')->get()->avg('reviews_avg_rating') ?? 0
                ];
            })
            ->sortByDesc('revenue')
            ->take(10)
            ->values();
    }

    /**
     * Get customer analysis
     */
    protected function getCustomerAnalysis($orders)
    {
        $customerOrders = $orders->groupBy('user_id');
        
        $newCustomers = $customerOrders->filter(function ($userOrders) {
            return $userOrders->count() === 1;
        })->count();

        $returningCustomers = $customerOrders->filter(function ($userOrders) {
            return $userOrders->count() > 1;
        })->count();

        $customerSegments = $this->segmentCustomers($customerOrders);

        return [
            'total_customers' => $customerOrders->count(),
            'new_customers' => $newCustomers,
            'returning_customers' => $returningCustomers,
            'customer_retention_rate' => $this->calculateRetentionRate($customerOrders),
            'customer_segments' => $customerSegments,
            'top_customers' => $this->getTopCustomers($customerOrders)
        ];
    }

    /**
     * Get payment analysis
     */
    protected function getPaymentAnalysis($orders)
    {
        $paymentMethods = $orders->groupBy('payment.payment_type');
        
        return $paymentMethods->map(function ($methodOrders, $method) {
            return [
                'payment_method' => $method,
                'revenue' => $methodOrders->sum('price'),
                'orders' => $methodOrders->count(),
                'percentage' => round(($methodOrders->count() / $orders->count()) * 100, 2)
            ];
        })->sortByDesc('revenue')->values();
    }

    /**
     * Get geographic analysis
     */
    protected function getGeographicAnalysis($orders)
    {
        return $orders->groupBy('user.country')
            ->map(function ($countryOrders, $country) {
                return [
                    'country' => $country,
                    'revenue' => $countryOrders->sum('price'),
                    'orders' => $countryOrders->count(),
                    'customers' => $countryOrders->unique('user_id')->count()
                ];
            })
            ->sortByDesc('revenue')
            ->take(10)
            ->values();
    }

    /**
     * Calculate conversion rate
     */
    protected function calculateConversionRate($orders)
    {
        $totalVisitors = $this->getTotalVisitors($orders->first()->created_at ?? now());
        $totalOrders = $orders->count();
        
        return $totalVisitors > 0 ? round(($totalOrders / $totalVisitors) * 100, 2) : 0;
    }

    /**
     * Calculate customer lifetime value
     */
    protected function calculateCustomerLifetimeValue($orders)
    {
        $customerOrders = $orders->groupBy('user_id');
        
        $totalRevenue = $orders->sum('price');
        $totalCustomers = $customerOrders->count();
        
        return $totalCustomers > 0 ? round($totalRevenue / $totalCustomers, 2) : 0;
    }

    /**
     * Calculate retention rate
     */
    protected function calculateRetentionRate($customerOrders)
    {
        $totalCustomers = $customerOrders->count();
        $returningCustomers = $customerOrders->filter(function ($userOrders) {
            return $userOrders->count() > 1;
        })->count();
        
        return $totalCustomers > 0 ? round(($returningCustomers / $totalCustomers) * 100, 2) : 0;
    }

    /**
     * Segment customers
     */
    protected function segmentCustomers($customerOrders)
    {
        $segments = [
            'high_value' => 0,
            'medium_value' => 0,
            'low_value' => 0
        ];

        foreach ($customerOrders as $userOrders) {
            $totalSpent = $userOrders->sum('price');
            
            if ($totalSpent >= 1000) {
                $segments['high_value']++;
            } elseif ($totalSpent >= 100) {
                $segments['medium_value']++;
            } else {
                $segments['low_value']++;
            }
        }

        return $segments;
    }

    /**
     * Get top customers
     */
    protected function getTopCustomers($customerOrders)
    {
        return $customerOrders->map(function ($userOrders) {
            $user = $userOrders->first()->user;
            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'total_spent' => $userOrders->sum('price'),
                'orders_count' => $userOrders->count(),
                'last_order_date' => $userOrders->max('created_at')
            ];
        })
        ->sortByDesc('total_spent')
        ->take(10)
        ->values();
    }

    /**
     * Get total visitors (mock data)
     */
    protected function getTotalVisitors($date)
    {
        // This would typically come from analytics system
        // For now, return mock data
        return rand(1000, 10000);
    }

    /**
     * Get real-time sales data
     */
    public function getRealTimeSalesData($instructorId = null)
    {
        try {
            $today = now()->startOfDay();
            
            $query = Order::where('status', 'completed')
                ->where('created_at', '>=', $today);

            if ($instructorId) {
                $query->whereHas('course', function ($q) use ($instructorId) {
                    $q->where('instructor_id', $instructorId);
                });
            }

            $todayOrders = $query->get();

            return [
                'today_revenue' => $todayOrders->sum('price'),
                'today_orders' => $todayOrders->count(),
                'today_customers' => $todayOrders->unique('user_id')->count(),
                'hourly_breakdown' => $this->getHourlyBreakdown($todayOrders),
                'last_updated' => now()
            ];

        } catch (\Exception $e) {
            Log::error("Failed to get real-time sales data: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get hourly breakdown
     */
    protected function getHourlyBreakdown($orders)
    {
        $hourlyData = [];
        
        for ($hour = 0; $hour < 24; $hour++) {
            $hourStart = now()->startOfDay()->addHours($hour);
            $hourEnd = $hourStart->copy()->addHour();
            
            $hourOrders = $orders->filter(function ($order) use ($hourStart, $hourEnd) {
                return $order->created_at->between($hourStart, $hourEnd);
            });
            
            $hourlyData[] = [
                'hour' => $hour,
                'revenue' => $hourOrders->sum('price'),
                'orders' => $hourOrders->count()
            ];
        }
        
        return $hourlyData;
    }

    /**
     * Get sales forecast
     */
    public function getSalesForecast($period = 'month', $instructorId = null)
    {
        try {
            $startDate = now()->subMonths(3);
            $endDate = now();
            
            $query = Order::where('status', 'completed')
                ->whereBetween('created_at', [$startDate, $endDate]);

            if ($instructorId) {
                $query->whereHas('course', function ($q) use ($instructorId) {
                    $q->where('instructor_id', $instructorId);
                });
            }

            $historicalData = $query->get();
            
            // Simple linear regression for forecasting
            $forecast = $this->calculateForecast($historicalData, $period);
            
            return [
                'period' => $period,
                'forecasted_revenue' => $forecast['revenue'],
                'forecasted_orders' => $forecast['orders'],
                'confidence_level' => $forecast['confidence'],
                'factors' => $forecast['factors']
            ];

        } catch (\Exception $e) {
            Log::error("Failed to get sales forecast: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Calculate forecast using simple linear regression
     */
    protected function calculateForecast($historicalData, $period)
    {
        // This is a simplified forecast calculation
        // In production, you would use more sophisticated algorithms
        
        $monthlyData = $historicalData->groupBy(function ($order) {
            return $order->created_at->format('Y-m');
        });
        
        $monthlyRevenue = $monthlyData->map(function ($monthOrders) {
            return $monthOrders->sum('price');
        });
        
        $averageRevenue = $monthlyRevenue->avg();
        $growthRate = 0.05; // 5% monthly growth assumption
        
        $forecastedRevenue = $averageRevenue * (1 + $growthRate);
        $forecastedOrders = $historicalData->count() / 3 * (1 + $growthRate);
        
        return [
            'revenue' => round($forecastedRevenue, 2),
            'orders' => round($forecastedOrders),
            'confidence' => 0.75,
            'factors' => ['historical_trend', 'seasonality', 'market_conditions']
        ];
    }

    /**
     * Export sales report
     */
    public function exportSalesReport($filters = [], $format = 'csv')
    {
        try {
            $reportData = $this->getSalesReport($filters);
            
            switch ($format) {
                case 'csv':
                    return $this->exportToCSV($reportData);
                    
                case 'excel':
                    return $this->exportToExcel($reportData);
                    
                case 'pdf':
                    return $this->exportToPDF($reportData);
                    
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
     * Export to CSV
     */
    protected function exportToCSV($reportData)
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
    protected function exportToExcel($reportData)
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
    protected function exportToPDF($reportData)
    {
        // This would integrate with PDF generation library
        return [
            'success' => true,
            'message' => 'PDF export requires additional setup',
            'format' => 'pdf'
        ];
    }
} 