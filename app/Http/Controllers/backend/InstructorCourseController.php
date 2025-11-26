<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Course;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class InstructorCourseController extends Controller
{
    /**
     * Display a listing of courses
     */
    public function index()
    {
        $courses = Course::where('instructor_id', Auth::id())->latest()->get();
        return view('backend.instructor.course.index', compact('courses'));
    }

    /**
     * Show the course creation wizard
     */
    public function create()
    {
        $all_categories = Category::all();
        return view('backend.instructor.course.create', compact('all_categories'));
    }

    /**
     * Store course data step by step
     */
    public function store(Request $request)
    {
        $request->validate([
            'course_name' => 'required|string|max:255',
            'course_title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'subcategory_id' => 'required|exists:sub_categories,id',
            'level' => 'required|in:beginner,intermediate,advanced',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'video_url' => 'required|url',
            'description' => 'required|string',
            'selling_price' => 'required|numeric|min:0',
            'duration' => 'required|numeric|min:0.1',
            'certificate' => 'required|in:yes,no',
        ]);

        // Generate slug automatically
        $slug = Str::slug($request->course_name);

        // Handle image upload
        $imagePath = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '_' . $image->getClientOriginalName();
            $imagePath = $image->storeAs('courses', $imageName, 'public');
        }

        // Create course
        $course = Course::create([
            'instructor_id' => Auth::id(),
            'course_name' => $request->course_name,
            'course_name_slug' => $slug,
            'course_title' => $request->course_title,
            'category_id' => $request->category_id,
            'subcategory_id' => $request->subcategory_id,
            'level' => $request->level,
            'image' => $imagePath,
            'video_url' => $request->video_url,
            'description' => $request->description,
            'prerequisites' => $request->prerequisites,
            'course_goals' => $request->course_goals ? json_encode($request->course_goals) : null,
            'selling_price' => $request->selling_price,
            'discount_price' => $request->discount_price,
            'duration' => $request->duration,
            'resources' => $request->resources,
            'certificate' => $request->certificate,
            'bestseller' => $request->bestseller ?? 'no',
            'featured' => $request->featured ?? 'no',
            'highestrated' => $request->highestrated ?? 'no',
            'status' => 'draft', // Start as draft
        ]);

        return redirect()->route('instructor.course.edit', $course->id)
            ->with('success', 'Course created successfully! Complete the remaining details.');
    }

    /**
     * Show course edit form
     */
    public function edit($id)
    {
        $course = Course::where('id', $id)->where('instructor_id', Auth::id())->firstOrFail();
        $all_categories = Category::all();
        $subcategories = SubCategory::where('category_id', $course->category_id)->get();
        
        return view('backend.instructor.course.edit', compact('course', 'all_categories', 'subcategories'));
    }

    /**
     * Update course
     */
    public function update(Request $request, $id)
    {
        $course = Course::where('id', $id)->where('instructor_id', Auth::id())->firstOrFail();
        
        $request->validate([
            'course_name' => 'required|string|max:255',
            'course_title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'subcategory_id' => 'required|exists:sub_categories,id',
            'level' => 'required|in:beginner,intermediate,advanced',
            'description' => 'required|string',
            'selling_price' => 'required|numeric|min:0',
            'duration' => 'required|numeric|min:0.1',
        ]);

        // Handle image upload if new image
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '_' . $image->getClientOriginalName();
            $imagePath = $image->storeAs('courses', $imageName, 'public');
            $course->image = $imagePath;
        }

        // Update course
        $course->update([
            'course_name' => $request->course_name,
            'course_title' => $request->course_title,
            'category_id' => $request->category_id,
            'subcategory_id' => $request->subcategory_id,
            'level' => $request->level,
            'video_url' => $request->video_url,
            'description' => $request->description,
            'prerequisites' => $request->prerequisites,
            'course_goals' => $request->course_goals ? json_encode($request->course_goals) : null,
            'selling_price' => $request->selling_price,
            'discount_price' => $request->discount_price,
            'duration' => $request->duration,
            'resources' => $request->resources,
            'certificate' => $request->certificate,
            'bestseller' => $request->bestseller ?? 'no',
            'featured' => $request->featured ?? 'no',
            'highestrated' => $request->highestrated ?? 'no',
        ]);

        return redirect()->route('instructor.course.index')
            ->with('success', 'Course updated successfully!');
    }

    /**
     * Publish course
     */
    public function publish($id)
    {
        $course = Course::where('id', $id)->where('instructor_id', Auth::id())->firstOrFail();
        $course->update(['status' => 'published']);
        
        return redirect()->route('instructor.course.index')
            ->with('success', 'Course published successfully!');
    }

    /**
     * Get subcategories for AJAX
     */
    public function getSubcategories($categoryId)
    {
        $subcategories = SubCategory::where('category_id', $categoryId)->get();
        return response()->json($subcategories);
    }

    /**
     * Show drafts
     */
    public function drafts()
    {
        $courses = Course::where('instructor_id', Auth::id())
            ->where('status', 'draft')
            ->latest()
            ->get();
        return view('backend.instructor.course.drafts', compact('courses'));
    }

    /**
     * Show published courses
     */
    public function published()
    {
        $courses = Course::where('instructor_id', Auth::id())
            ->where('status', 'published')
            ->latest()
            ->get();
        return view('backend.instructor.course.published', compact('courses'));
    }

    /**
     * Destroy course
     */
    public function destroy($id)
    {
        $course = Course::where('id', $id)->where('instructor_id', Auth::id())->firstOrFail();
        $course->delete();
        
        return redirect()->route('instructor.course.index')
            ->with('success', 'Course deleted successfully!');
    }
}










