<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_code',
        'state_code',
        'rate',
        'tax_name',
        'description',
        'is_active',
        'effective_date',
        'expiry_date'
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'is_active' => 'boolean',
        'effective_date' => 'date',
        'expiry_date' => 'date'
    ];

    /**
     * Get the country that owns the tax rate.
     */
    public function country()
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    /**
     * Get the state that owns the tax rate.
     */
    public function state()
    {
        return $this->belongsTo(State::class, 'state_code', 'code');
    }

    /**
     * Scope to get active tax rates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get tax rates for a specific country.
     */
    public function scopeForCountry($query, $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope to get tax rates for a specific state.
     */
    public function scopeForState($query, $stateCode)
    {
        return $query->where('state_code', $stateCode);
    }

    /**
     * Scope to get currently effective tax rates.
     */
    public function scopeCurrentlyEffective($query)
    {
        $now = now();
        return $query->where(function ($q) use ($now) {
            $q->whereNull('effective_date')
              ->orWhere('effective_date', '<=', $now);
        })->where(function ($q) use ($now) {
            $q->whereNull('expiry_date')
              ->orWhere('expiry_date', '>', $now);
        });
    }

    /**
     * Get formatted tax rate.
     */
    public function getFormattedRateAttribute()
    {
        return number_format($this->rate * 100, 2) . '%';
    }

    /**
     * Check if tax rate is currently effective.
     */
    public function isCurrentlyEffective()
    {
        $now = now();
        
        if ($this->effective_date && $this->effective_date > $now) {
            return false;
        }
        
        if ($this->expiry_date && $this->expiry_date <= $now) {
            return false;
        }
        
        return true;
    }

    /**
     * Get tax amount for a given price.
     */
    public function calculateTaxAmount($price)
    {
        return $price * $this->rate;
    }

    /**
     * Get total amount including tax.
     */
    public function calculateTotalAmount($price)
    {
        return $price * (1 + $this->rate);
    }
} 