<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->get('type'); // instructor veya customer
        $plans = SubscriptionPlan::where('is_active', true)
            ->when($type, fn($q) => $q->where('type', $type))
            ->orderBy('price', 'asc')
            ->get();
        
        // API isteği ise JSON döndür
        if ($request->wantsJson()) {
            return response()->json($plans);
        }
        // Web için view döndür (ileride pricing page için kullanılacak)
        return view('frontend.pages.pricing', compact('plans'));
    }

    public function subscribe(Request $request, SubscriptionPlan $plan)
    {
        $user = $request->user();

        // Eğer zaten aktif bir aboneliği varsa, uyarı ver
        $active = $user->userSubscriptions()
            ->where('status', 'active')
            ->where('end_date', '>=', now())
            ->first();
        if ($active) {
            return back()->with('error', 'You already have an active subscription.');
        }

        // Ücretsiz plan ise doğrudan abone et
        if ($plan->price == 0) {
            $user->userSubscriptions()->create([
                'plan_id' => $plan->id,
                'status' => 'active',
                'start_date' => now(),
                'end_date' => now()->addDays($plan->duration_days),
                'auto_renew' => false,
            ]);
            return redirect()->route('dashboard')->with('success', 'Subscription started!');
        }

        // Ücretli plan ise ödeme sayfasına yönlendir (şimdilik dummy bir sayfa)
        return redirect()->route('pricing')->with('info', 'Payment integration coming soon!');
    }
} 