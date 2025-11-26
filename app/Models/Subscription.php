<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'stripe_subscription_id',
        'stripe_customer_id',
        'status',
        'amount',
        'currency',
        'billing_cycle',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'canceled_at',
        'ended_at',
        'auto_renew',
        'metadata'
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'canceled_at' => 'datetime',
        'ended_at' => 'datetime',
        'auto_renew' => 'boolean',
        'metadata' => 'array',
        'amount' => 'decimal:2'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function isActive()
    {
        return $this->status === 'active';
    }

    public function isCanceled()
    {
        return $this->status === 'canceled';
    }

    public function isPastDue()
    {
        return $this->status === 'past_due';
    }

    public function isTrialing()
    {
        return $this->status === 'trialing';
    }

    public function isUnpaid()
    {
        return $this->status === 'unpaid';
    }

    public function onTrial()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function hasExpired()
    {
        return $this->current_period_end && $this->current_period_end->isPast();
    }

    public function willRenew()
    {
        return $this->auto_renew && !$this->isCanceled() && !$this->hasExpired();
    }

    public function daysUntilRenewal()
    {
        if (!$this->current_period_end) {
            return null;
        }

        return Carbon::now()->diffInDays($this->current_period_end, false);
    }

    public function daysUntilTrialEnd()
    {
        if (!$this->trial_ends_at) {
            return null;
        }

        return Carbon::now()->diffInDays($this->trial_ends_at, false);
    }
} 