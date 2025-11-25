<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'exchange_rate',
        'is_active',
        'is_default',
        'decimal_places',
        'position'
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:6',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'decimal_places' => 'integer'
    ];

    public function formatAmount($amount)
    {
        $formatted = number_format($amount, $this->decimal_places);
        
        if ($this->position === 'left') {
            return $this->symbol . $formatted;
        } else {
            return $formatted . $this->symbol;
        }
    }

    public function convertFrom($amount, $fromCurrency)
    {
        if ($fromCurrency instanceof Currency) {
            $fromCurrency = $fromCurrency->code;
        }
        
        if ($fromCurrency === $this->code) {
            return $amount;
        }
        
        // Get exchange rate from fromCurrency to USD, then to this currency
        $fromCurrencyModel = self::where('code', $fromCurrency)->first();
        if (!$fromCurrencyModel) {
            return $amount;
        }
        
        // Convert to USD first, then to target currency
        $usdAmount = $amount / $fromCurrencyModel->exchange_rate;
        return $usdAmount * $this->exchange_rate;
    }

    public function convertTo($amount, $toCurrency)
    {
        if ($toCurrency instanceof Currency) {
            $toCurrency = $toCurrency->code;
        }
        
        if ($toCurrency === $this->code) {
            return $amount;
        }
        
        // Get exchange rate from this currency to USD, then to target currency
        $toCurrencyModel = self::where('code', $toCurrency)->first();
        if (!$toCurrencyModel) {
            return $amount;
        }
        
        // Convert to USD first, then to target currency
        $usdAmount = $amount / $this->exchange_rate;
        return $usdAmount * $toCurrencyModel->exchange_rate;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public static function getDefault()
    {
        return self::default()->first() ?? self::where('code', 'USD')->first();
    }

    public static function getActive()
    {
        return self::active()->get();
    }
} 