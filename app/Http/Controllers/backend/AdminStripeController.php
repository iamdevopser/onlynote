<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserSubscription;
use App\Models\StripePayment;
use App\Models\User;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Log;

class AdminStripeController extends Controller
{
    /**
     * Stripe abonelik listesi
     */
    public function subscriptions(Request $request)
    {
        $subscriptions = UserSubscription::with(['user', 'subscriptionPlan'])
            ->when($request->status, function($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->search, function($query, $search) {
                return $query->whereHas('user', function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('backend.admin.stripe.subscriptions', compact('subscriptions'));
    }

    /**
     * Stripe ödeme geçmişi
     */
    public function payments(Request $request)
    {
        $payments = StripePayment::with(['user', 'order'])
            ->when($request->status, function($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->search, function($query, $search) {
                return $query->whereHas('user', function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('backend.admin.stripe.payments', compact('payments'));
    }

    /**
     * Kullanıcı abonelik detayı
     */
    public function userSubscription($userId)
    {
        $user = User::with(['subscriptions.subscriptionPlan'])->findOrFail($userId);
        $subscriptions = $user->subscriptions()->orderBy('created_at', 'desc')->get();
        
        return view('backend.admin.stripe.user-subscription', compact('user', 'subscriptions'));
    }

    /**
     * Abonelik durumu güncelle
     */
    public function updateSubscriptionStatus(Request $request, $subscriptionId)
    {
        $request->validate([
            'status' => 'required|in:active,canceled,past_due,unpaid'
        ]);

        $subscription = UserSubscription::findOrFail($subscriptionId);
        $subscription->update(['status' => $request->status]);

        return redirect()->back()->with('success', 'Abonelik durumu güncellendi.');
    }

    /**
     * Stripe istatistikleri
     */
    public function statistics()
    {
        $stats = [
            'total_subscriptions' => UserSubscription::count(),
            'active_subscriptions' => UserSubscription::where('status', 'active')->count(),
            'total_revenue' => StripePayment::where('status', 'succeeded')->sum('amount'),
            'monthly_revenue' => StripePayment::where('status', 'succeeded')
                ->whereMonth('created_at', now()->month)
                ->sum('amount'),
        ];

        return view('backend.admin.stripe.statistics', compact('stats'));
    }
} 