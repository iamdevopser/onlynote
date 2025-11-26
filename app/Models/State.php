<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'country_code',
        'type',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    /**
     * Get the country that owns the state.
     */
    public function country()
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    /**
     * Get the tax rates for the state.
     */
    public function taxRates()
    {
        return $this->hasMany(TaxRate::class, 'state_code', 'code');
    }

    /**
     * Scope to get active states.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get states for a specific country.
     */
    public function scopeForCountry($query, $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Get state by code and country.
     */
    public static function findByCodeAndCountry($code, $countryCode)
    {
        return static::where('code', $code)
            ->where('country_code', $countryCode)
            ->first();
    }

    /**
     * Get full state name with country.
     */
    public function getFullNameAttribute()
    {
        return "{$this->name}, {$this->country->name}";
    }

    /**
     * Get state type display name.
     */
    public function getTypeDisplayNameAttribute()
    {
        $types = [
            'state' => 'State',
            'province' => 'Province',
            'region' => 'Region',
            'territory' => 'Territory',
            'district' => 'District',
            'county' => 'County'
        ];

        return $types[$this->type] ?? ucfirst($this->type);
    }
} 