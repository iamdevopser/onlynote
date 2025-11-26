<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'phone_code',
        'currency_code',
        'currency_symbol',
        'timezone',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    /**
     * Get the states for the country.
     */
    public function states()
    {
        return $this->hasMany(State::class, 'country_code', 'code');
    }

    /**
     * Get the tax rates for the country.
     */
    public function taxRates()
    {
        return $this->hasMany(TaxRate::class, 'country_code', 'code');
    }

    /**
     * Scope to get active countries.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get country by code.
     */
    public static function findByCode($code)
    {
        return static::where('code', $code)->first();
    }

    /**
     * Get formatted phone code.
     */
    public function getFormattedPhoneCodeAttribute()
    {
        return $this->phone_code ? '+' . $this->phone_code : '';
    }

    /**
     * Get country flag emoji.
     */
    public function getFlagEmojiAttribute()
    {
        $code = strtoupper($this->code);
        $flag = '';
        
        for ($i = 0; $i < strlen($code); $i++) {
            $flag .= chr(ord($code[$i]) + 127397);
        }
        
        return $flag;
    }
} 