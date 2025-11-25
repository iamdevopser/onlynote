<?php

namespace App\Http\Controllers;

use App\Models\Wishlist;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WishlistController extends Controller
{
    /**
     * Display the user's wishlist.
     */
    public function index()
    {
        $wishlistItems = auth()->user()->wishlist()
            ->with(['course.category', 'course.user'])
            ->orderBy('added_at', 'desc')
            ->paginate(12);

        return view('frontend.pages.wishlist.index', compact('wishlistItems'));
    }

    /**
     * Add a course to wishlist.
     */
    public function add(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id'
        ]);

        $userId = auth()->id();
        $courseId = $request->course_id;

        // Check if already in wishlist
        if (Wishlist::isInWishlist($userId, $courseId)) {
            return response()->json([
                'success' => false,
                'message' => 'Course is already in your wishlist.'
            ], 400);
        }

        // Add to wishlist
        Wishlist::addToWishlist($userId, $courseId);

        $wishlistCount = Wishlist::getUserWishlistCount($userId);

        return response()->json([
            'success' => true,
            'message' => 'Course added to wishlist successfully.',
            'wishlist_count' => $wishlistCount
        ]);
    }

    /**
     * Remove a course from wishlist.
     */
    public function remove(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id'
        ]);

        $userId = auth()->id();
        $courseId = $request->course_id;

        // Remove from wishlist
        $removed = Wishlist::removeFromWishlist($userId, $courseId);

        if (!$removed) {
            return response()->json([
                'success' => false,
                'message' => 'Course was not in your wishlist.'
            ], 400);
        }

        $wishlistCount = Wishlist::getUserWishlistCount($userId);

        return response()->json([
            'success' => true,
            'message' => 'Course removed from wishlist successfully.',
            'wishlist_count' => $wishlistCount
        ]);
    }

    /**
     * Toggle wishlist status (add/remove).
     */
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id'
        ]);

        $userId = auth()->id();
        $courseId = $request->course_id;

        $isInWishlist = Wishlist::isInWishlist($userId, $courseId);

        if ($isInWishlist) {
            // Remove from wishlist
            Wishlist::removeFromWishlist($userId, $courseId);
            $message = 'Course removed from wishlist.';
            $action = 'removed';
        } else {
            // Add to wishlist
            Wishlist::addToWishlist($userId, $courseId);
            $message = 'Course added to wishlist.';
            $action = 'added';
        }

        $wishlistCount = Wishlist::getUserWishlistCount($userId);

        return response()->json([
            'success' => true,
            'message' => $message,
            'action' => $action,
            'wishlist_count' => $wishlistCount,
            'is_in_wishlist' => !$isInWishlist
        ]);
    }

    /**
     * Check if a course is in user's wishlist.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id'
        ]);

        $userId = auth()->id();
        $courseId = $request->course_id;

        $isInWishlist = Wishlist::isInWishlist($userId, $courseId);

        return response()->json([
            'success' => true,
            'is_in_wishlist' => $isInWishlist
        ]);
    }

    /**
     * Get user's wishlist count.
     */
    public function count(): JsonResponse
    {
        $userId = auth()->id();
        $count = Wishlist::getUserWishlistCount($userId);

        return response()->json([
            'success' => true,
            'count' => $count
        ]);
    }

    /**
     * Clear all items from wishlist.
     */
    public function clear(): JsonResponse
    {
        $userId = auth()->id();
        
        $deleted = Wishlist::where('user_id', $userId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Wishlist cleared successfully.',
            'deleted_count' => $deleted
        ]);
    }

    /**
     * Move wishlist item to cart.
     */
    public function moveToCart(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id'
        ]);

        $userId = auth()->id();
        $courseId = $request->course_id;

        // Check if course is in wishlist
        if (!Wishlist::isInWishlist($userId, $courseId)) {
            return response()->json([
                'success' => false,
                'message' => 'Course is not in your wishlist.'
            ], 400);
        }

        // Add to cart (assuming you have a Cart model)
        // Cart::addToCart($userId, $courseId);

        // Remove from wishlist
        Wishlist::removeFromWishlist($userId, $courseId);

        return response()->json([
            'success' => true,
            'message' => 'Course moved to cart successfully.'
        ]);
    }
} 