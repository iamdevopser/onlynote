<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StripePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'stripe_payment_intent_id',
        'stripe_customer_id',
        'payment_method_id',
        'amount',
        'currency',
        'status',
        'payment_method_type',
        'payment_method_details',
        'metadata',
        'paid_at'
    ];

    protected $casts = [
        'payment_method_details' => 'array',
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function isSuccessful()
    {
        return $this->status === 'succeeded';
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isFailed()
    {
        return $this->status === 'failed';
    }

    public function isCanceled()
    {
        return $this->status === 'canceled';
    }
}
