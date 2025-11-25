<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    /**
     * Display search results.
     */
    public function index(Request $request)
    {
        $query = $request->get('q', '');
        $category = $request->get('category');
        $subcategory = $request->get('subcategory');
        $price_min = $request->get('price_min');
        $price_max = $request->get('price_max');
        $rating = $request->get('rating');
        $level = $request->get('level');
        $duration = $request->get('duration');
        $sort = $request->get('sort', 'relevance');
        $per_page = $request->get('per_page', 12);

        // Get all categories for filter
        $categories = Category::with('subcategory')->get();

        // Build query
        $courses = Course::with(['category', 'subCategory', 'user'])
            ->where('status', 'published');

        // Search query
        if (!empty($query)) {
            $courses->where(function($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%")
                  ->orWhere('short_description', 'LIKE', "%{$query}%")
                  ->orWhereHas('category', function($cat) use ($query) {
                      $cat->where('name', 'LIKE', "%{$query}%");
                  })
                  ->orWhereHas('subCategory', function($sub) use ($query) {
                      $sub->where('name', 'LIKE', "%{$query}%");
                  })
                  ->orWhereHas('user', function($user) use ($query) {
                      $user->where('name', 'LIKE', "%{$query}%");
                  });
            });
        }

        // Category filter
        if (!empty($category)) {
            $courses->where('category_id', $category);
        }

        // Subcategory filter
        if (!empty($subcategory)) {
            $courses->where('subcategory_id', $subcategory);
        }

        // Price range filter
        if (!empty($price_min)) {
            $courses->where('price', '>=', $price_min);
        }
        if (!empty($price_max)) {
            $courses->where('price', '<=', $price_max);
        }

        // Rating filter
        if (!empty($rating)) {
            $courses->where('rating', '>=', $rating);
        }

        // Level filter
        if (!empty($level)) {
            $courses->where('level', $level);
        }

        // Duration filter
        if (!empty($duration)) {
            switch ($duration) {
                case '0-2':
                    $courses->where('duration', '<=', 120); // 2 hours
                    break;
                case '2-5':
                    $courses->whereBetween('duration', [120, 300]); // 2-5 hours
                    break;
                case '5-10':
                    $courses->whereBetween('duration', [300, 600]); // 5-10 hours
                    break;
                case '10+':
                    $courses->where('duration', '>', 600); // 10+ hours
                    break;
            }
        }

        // Sorting
        switch ($sort) {
            case 'price_low':
                $courses->orderBy('price', 'asc');
                break;
            case 'price_high':
                $courses->orderBy('price', 'desc');
                break;
            case 'rating':
                $courses->orderBy('rating', 'desc');
                break;
            case 'newest':
                $courses->orderBy('created_at', 'desc');
                break;
            case 'popular':
                $courses->orderBy('students_count', 'desc');
                break;
            default: // relevance
                if (!empty($query)) {
                    $courses->orderByRaw("
                        CASE 
                            WHEN title LIKE ? THEN 1
                            WHEN description LIKE ? THEN 2
                            ELSE 3
                        END
                    ", ["%{$query}%", "%{$query}%"]);
                }
                $courses->orderBy('rating', 'desc');
                break;
        }

        $courses = $courses->paginate($per_page);

        // For AJAX requests
        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'courses' => $courses->items(),
                'pagination' => [
                    'current_page' => $courses->currentPage(),
                    'last_page' => $courses->lastPage(),
                    'per_page' => $courses->perPage(),
                    'total' => $courses->total(),
                ],
                'filters' => [
                    'query' => $query,
                    'category' => $category,
                    'subcategory' => $subcategory,
                    'price_min' => $price_min,
                    'price_max' => $price_max,
                    'rating' => $rating,
                    'level' => $level,
                    'duration' => $duration,
                    'sort' => $sort,
                ]
            ]);
        }

        return view('frontend.pages.search.index', compact(
            'courses', 
            'categories', 
            'query',
            'category',
            'subcategory',
            'price_min',
            'price_max',
            'rating',
            'level',
            'duration',
            'sort'
        ));
    }

    /**
     * Get subcategories for a category.
     */
    public function getSubcategories(Request $request): JsonResponse
    {
        $categoryId = $request->get('category_id');
        
        $subcategories = SubCategory::where('category_id', $categoryId)
            ->select('id', 'name')
            ->get();

        return response()->json([
            'success' => true,
            'subcategories' => $subcategories
        ]);
    }

    /**
     * Live search suggestions.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $query = $request->get('q', '');
        
        if (strlen($query) < 2) {
            return response()->json([
                'success' => true,
                'suggestions' => []
            ]);
        }

        $suggestions = [];

        // Course suggestions
        $courses = Course::where('status', 'published')
            ->where('title', 'LIKE', "%{$query}%")
            ->select('id', 'title', 'slug')
            ->limit(5)
            ->get();

        foreach ($courses as $course) {
            $suggestions[] = [
                'type' => 'course',
                'id' => $course->id,
                'title' => $course->title,
                'url' => route('course-details', $course->slug ?? $course->id),
                'icon' => 'fas fa-graduation-cap'
            ];
        }

        // Category suggestions
        $categories = Category::where('name', 'LIKE', "%{$query}%")
            ->select('id', 'name')
            ->limit(3)
            ->get();

        foreach ($categories as $category) {
            $suggestions[] = [
                'type' => 'category',
                'id' => $category->id,
                'title' => $category->name,
                'url' => route('frontend.search', ['category' => $category->id]),
                'icon' => 'fas fa-folder'
            ];
        }

        // Instructor suggestions
        $instructors = \App\Models\User::where('role', 'instructor')
            ->where('name', 'LIKE', "%{$query}%")
            ->select('id', 'name')
            ->limit(3)
            ->get();

        foreach ($instructors as $instructor) {
            $suggestions[] = [
                'type' => 'instructor',
                'id' => $instructor->id,
                'title' => $instructor->name,
                'url' => route('frontend.search', ['instructor' => $instructor->id]),
                'icon' => 'fas fa-user-tie'
            ];
        }

        return response()->json([
            'success' => true,
            'suggestions' => $suggestions
        ]);
    }

    /**
     * Advanced search with filters.
     */
    public function advanced(Request $request)
    {
        $filters = $request->all();
        
        $courses = Course::with(['category', 'subCategory', 'user'])
            ->where('status', 'published');

        // Apply advanced filters
        if (!empty($filters['languages'])) {
            $courses->whereIn('language', $filters['languages']);
        }

        if (!empty($filters['certificate'])) {
            $courses->where('certificate', $filters['certificate']);
        }

        if (!empty($filters['access_lifetime'])) {
            $courses->where('access_lifetime', $filters['access_lifetime']);
        }

        if (!empty($filters['created_after'])) {
            $courses->where('created_at', '>=', $filters['created_after']);
        }

        if (!empty($filters['instructor'])) {
            $courses->where('instructor_id', $filters['instructor']);
        }

        // Apply basic filters (reuse from index method)
        $courses = $this->applyBasicFilters($courses, $filters);

        $courses = $courses->paginate(12);

        return view('frontend.pages.search.advanced', compact('courses', 'filters'));
    }

    /**
     * Apply basic filters to course query.
     */
    private function applyBasicFilters($query, $filters)
    {
        if (!empty($filters['q'])) {
            $query->where(function($q) use ($filters) {
                $q->where('title', 'LIKE', "%{$filters['q']}%")
                  ->orWhere('description', 'LIKE', "%{$filters['q']}%");
            });
        }

        if (!empty($filters['category'])) {
            $query->where('category_id', $filters['category']);
        }

        if (!empty($filters['price_min'])) {
            $query->where('price', '>=', $filters['price_min']);
        }

        if (!empty($filters['price_max'])) {
            $query->where('price', '<=', $filters['price_max']);
        }

        if (!empty($filters['rating'])) {
            $query->where('rating', '>=', $filters['rating']);
        }

        return $query;
    }
} 