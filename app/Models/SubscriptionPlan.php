<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'currency',
        'billing_cycle',
        'trial_days',
        'is_active',
        'is_popular',
        'features',
        'max_courses',
        'max_students',
        'priority_support',
        'certificate_creation',
        'advanced_analytics',
        'stripe_price_id',
        'stripe_product_id'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_popular' => 'boolean',
        'features' => 'array',
        'priority_support' => 'boolean',
        'certificate_creation' => 'boolean',
        'advanced_analytics' => 'boolean'
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscriptions()
    {
        return $this->subscriptions()->where('status', 'active');
    }

    public function isUnlimited($feature)
    {
        switch ($feature) {
            case 'courses':
                return $this->max_courses === null;
            case 'students':
                return $this->max_students === null;
            default:
                return false;
        }
    }

    public function getFeatureValue($feature)
    {
        switch ($feature) {
            case 'courses':
                return $this->max_courses;
            case 'students':
                return $this->max_students;
            case 'priority_support':
                return $this->priority_support;
            case 'certificate_creation':
                return $this->certificate_creation;
            case 'advanced_analytics':
                return $this->advanced_analytics;
            default:
                return null;
        }
    }

    public function hasFeature($feature)
    {
        $value = $this->getFeatureValue($feature);
        
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_numeric($value)) {
            return $value > 0 || $value === null; // null means unlimited
        }
        
        return false;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }

    public function scopeByBillingCycle($query, $cycle)
    {
        return $query->where('billing_cycle', $cycle);
    }
} 