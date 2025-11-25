<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Course;
use App\Models\ReviewVote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    /**
     * Display reviews for a course
     */
    public function index(Request $request, $courseId)
    {
        $course = Course::findOrFail($courseId);
        
        $reviews = Review::with(['user', 'helpfulVotes', 'unhelpfulVotes'])
            ->where('course_id', $courseId)
            ->published()
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // Get rating statistics
        $ratingStats = $this->getRatingStats($courseId);
        
        return view('frontend.reviews.index', compact('course', 'reviews', 'ratingStats'));
    }

    /**
     * Show the form for creating a new review
     */
    public function create($courseId)
    {
        $course = Course::findOrFail($courseId);
        
        // Check if user can review this course
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Yorum yapmak için giriş yapmalısınız.');
        }
        
        // Check if user has purchased the course
        $hasPurchased = $course->enrollments()->where('user_id', Auth::id())->exists();
        if (!$hasPurchased) {
            return redirect()->back()->with('error', 'Bu kursu satın almadığınız için yorum yapamazsınız.');
        }
        
        // Check if user already reviewed this course
        $existingReview = Review::where('user_id', Auth::id())->where('course_id', $courseId)->first();
        if ($existingReview) {
            return redirect()->back()->with('error', 'Bu kurs için zaten yorum yapmışsınız.');
        }
        
        return view('frontend.reviews.create', compact('course'));
    }

    /**
     * Store a newly created review
     */
    public function store(Request $request, $courseId)
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'required|string|max:255',
            'comment' => 'required|string|min:10|max:1000'
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $course = Course::findOrFail($courseId);
        
        // Verify user can review
        if (!Auth::check() || !$course->enrollments()->where('user_id', Auth::id())->exists()) {
            return redirect()->back()->with('error', 'Bu kurs için yorum yapamazsınız.');
        }

        try {
            $review = Review::create([
                'user_id' => Auth::id(),
                'course_id' => $courseId,
                'rating' => $request->rating,
                'title' => $request->title,
                'comment' => $request->comment,
                'is_verified' => true, // User purchased the course
                'is_approved' => false, // Needs admin approval
                'helpful_votes' => 0,
                'total_votes' => 0
            ]);

            // Update course rating
            $this->updateCourseRating($courseId);

            return redirect()->route('courses.show', $course->slug)
                ->with('success', 'Yorumunuz başarıyla gönderildi ve onay için bekliyor.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Yorum gönderilirken bir hata oluştu.')
                ->withInput();
        }
    }

    /**
     * Display the specified review
     */
    public function show($courseId, $reviewId)
    {
        $course = Course::findOrFail($courseId);
        $review = Review::with(['user', 'course', 'helpfulVotes', 'unhelpfulVotes'])
            ->where('course_id', $courseId)
            ->where('id', $reviewId)
            ->published()
            ->firstOrFail();

        return view('frontend.reviews.show', compact('course', 'review'));
    }

    /**
     * Show the form for editing the specified review
     */
    public function edit($courseId, $reviewId)
    {
        $course = Course::findOrFail($courseId);
        $review = Review::where('course_id', $courseId)
            ->where('id', $reviewId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return view('frontend.reviews.edit', compact('course', 'review'));
    }

    /**
     * Update the specified review
     */
    public function update(Request $request, $courseId, $reviewId)
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'required|string|max:255',
            'comment' => 'required|string|min:10|max:1000'
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $review = Review::where('course_id', $courseId)
            ->where('id', $reviewId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        try {
            $review->update([
                'rating' => $request->rating,
                'title' => $request->title,
                'comment' => $request->comment,
                'is_approved' => false // Reset approval status
            ]);

            // Update course rating
            $this->updateCourseRating($courseId);

            return redirect()->route('courses.show', $course->review->course->slug)
                ->with('success', 'Yorumunuz başarıyla güncellendi.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Yorum güncellenirken bir hata oluştu.')
                ->withInput();
        }
    }

    /**
     * Remove the specified review
     */
    public function destroy($courseId, $reviewId)
    {
        $review = Review::where('course_id', $courseId)
            ->where('id', $reviewId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        try {
            $courseSlug = $review->course->slug;
            $review->delete();

            // Update course rating
            $this->updateCourseRating($courseId);

            return redirect()->route('courses.show', $courseSlug)
                ->with('success', 'Yorumunuz başarıyla silindi.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Yorum silinirken bir hata oluştu.');
        }
    }

    /**
     * Vote on a review (helpful/unhelpful)
     */
    public function vote(Request $request, $reviewId)
    {
        if (!Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Oy vermek için giriş yapmalısınız.'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'is_helpful' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Geçersiz oy.'
            ], 422);
        }

        $review = Review::findOrFail($reviewId);
        
        // Check if user already voted
        $existingVote = ReviewVote::where('user_id', Auth::id())
            ->where('review_id', $reviewId)
            ->first();

        try {
            if ($existingVote) {
                // Update existing vote
                $existingVote->update(['is_helpful' => $request->is_helpful]);
            } else {
                // Create new vote
                ReviewVote::create([
                    'user_id' => Auth::id(),
                    'review_id' => $reviewId,
                    'is_helpful' => $request->is_helpful
                ]);
            }

            // Update review vote counts
            $this->updateReviewVoteCounts($reviewId);

            return response()->json([
                'success' => true,
                'message' => 'Oyunuz kaydedildi.',
                'helpful_votes' => $review->fresh()->helpful_votes,
                'total_votes' => $review->fresh()->total_votes
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Oy kaydedilirken bir hata oluştu.'
            ], 500);
        }
    }

    /**
     * Get rating statistics for a course
     */
    private function getRatingStats($courseId)
    {
        $reviews = Review::where('course_id', $courseId)->published();
        
        $totalReviews = $reviews->count();
        $averageRating = $reviews->avg('rating') ?? 0;
        
        $ratingDistribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $count = $reviews->where('rating', $i)->count();
            $percentage = $totalReviews > 0 ? round(($count / $totalReviews) * 100) : 0;
            $ratingDistribution[$i] = [
                'count' => $count,
                'percentage' => $percentage
            ];
        }

        return [
            'total_reviews' => $totalReviews,
            'average_rating' => round($averageRating, 1),
            'rating_distribution' => $ratingDistribution
        ];
    }

    /**
     * Update course rating based on reviews
     */
    private function updateCourseRating($courseId)
    {
        $course = Course::find($courseId);
        if (!$course) return;

        $avgRating = Review::where('course_id', $courseId)
            ->published()
            ->avg('rating') ?? 0;

        $totalReviews = Review::where('course_id', $courseId)
            ->published()
            ->count();

        $course->update([
            'rating' => round($avgRating, 1),
            'review_count' => $totalReviews
        ]);
    }

    /**
     * Update review vote counts
     */
    private function updateReviewVoteCounts($reviewId)
    {
        $review = Review::find($reviewId);
        if (!$review) return;

        $helpfulVotes = ReviewVote::where('review_id', $reviewId)
            ->where('is_helpful', true)
            ->count();

        $totalVotes = ReviewVote::where('review_id', $reviewId)->count();

        $review->update([
            'helpful_votes' => $helpfulVotes,
            'total_votes' => $totalVotes
        ]);
    }
} 