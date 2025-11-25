<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\TaxRate;
use App\Models\Country;
use App\Models\State;

class TaxCalculationService
{
    protected $defaultTaxRate = 0.18; // 18% default tax rate
    protected $cacheDuration = 3600; // 1 hour

    /**
     * Calculate tax for an order
     */
    public function calculateTax($amount, $countryCode, $stateCode = null, $options = [])
    {
        try {
            $taxRate = $this->getTaxRate($countryCode, $stateCode);
            $taxAmount = $amount * $taxRate;
            
            $result = [
                'amount' => $amount,
                'tax_rate' => $taxRate,
                'tax_amount' => round($taxAmount, 2),
                'total_amount' => round($amount + $taxAmount, 2),
                'country_code' => $countryCode,
                'state_code' => $stateCode,
                'tax_type' => $this->getTaxType($countryCode, $stateCode),
                'exempt' => $this->isTaxExempt($countryCode, $stateCode, $options)
            ];

            // Apply tax exemptions if applicable
            if ($result['exempt']) {
                $result['tax_amount'] = 0;
                $result['total_amount'] = $amount;
                $result['exemption_reason'] = $this->getExemptionReason($countryCode, $stateCode, $options);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Tax calculation error: ' . $e->getMessage());
            
            // Return default calculation on error
            return [
                'amount' => $amount,
                'tax_rate' => $this->defaultTaxRate,
                'tax_amount' => round($amount * $this->defaultTaxRate, 2),
                'total_amount' => round($amount * (1 + $this->defaultTaxRate), 2),
                'country_code' => $countryCode,
                'state_code' => $stateCode,
                'tax_type' => 'VAT',
                'exempt' => false,
                'error' => 'Tax calculation failed, using default rate'
            ];
        }
    }

    /**
     * Get tax rate for country and state
     */
    public function getTaxRate($countryCode, $stateCode = null)
    {
        $cacheKey = "tax_rate_{$countryCode}_{$stateCode}";
        
        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($countryCode, $stateCode) {
            // First try to get specific state tax rate
            if ($stateCode) {
                $taxRate = TaxRate::where('country_code', $countryCode)
                    ->where('state_code', $stateCode)
                    ->where('is_active', true)
                    ->first();
                
                if ($taxRate) {
                    return $taxRate->rate;
                }
            }

            // Fall back to country tax rate
            $taxRate = TaxRate::where('country_code', $countryCode)
                ->whereNull('state_code')
                ->where('is_active', true)
                ->first();
            
            if ($taxRate) {
                return $taxRate->rate;
            }

            // Return default tax rate
            return $this->defaultTaxRate;
        });
    }

    /**
     * Get tax type for country and state
     */
    protected function getTaxType($countryCode, $stateCode = null)
    {
        $taxTypes = [
            'US' => 'Sales Tax',
            'CA' => 'GST/HST',
            'AU' => 'GST',
            'GB' => 'VAT',
            'DE' => 'VAT',
            'FR' => 'VAT',
            'IT' => 'VAT',
            'ES' => 'VAT',
            'NL' => 'VAT',
            'BE' => 'VAT',
            'AT' => 'VAT',
            'PT' => 'VAT',
            'IE' => 'VAT',
            'LU' => 'VAT',
            'FI' => 'VAT',
            'SE' => 'VAT',
            'DK' => 'VAT',
            'PL' => 'VAT',
            'CZ' => 'VAT',
            'HU' => 'VAT',
            'RO' => 'VAT',
            'BG' => 'VAT',
            'HR' => 'VAT',
            'SI' => 'VAT',
            'SK' => 'VAT',
            'EE' => 'VAT',
            'LV' => 'VAT',
            'LT' => 'VAT',
            'MT' => 'VAT',
            'CY' => 'VAT',
            'GR' => 'VAT',
            'TR' => 'KDV'
        ];

        return $taxTypes[$countryCode] ?? 'VAT';
    }

    /**
     * Check if order is tax exempt
     */
    protected function isTaxExempt($countryCode, $stateCode = null, $options = [])
    {
        // Check for tax-exempt organizations
        if (isset($options['tax_exempt_number']) && !empty($options['tax_exempt_number'])) {
            return true;
        }

        // Check for educational institutions
        if (isset($options['is_educational']) && $options['is_educational']) {
            return true;
        }

        // Check for government entities
        if (isset($options['is_government']) && $options['is_government']) {
            return true;
        }

        // Check for non-profit organizations
        if (isset($options['is_non_profit']) && $options['is_non_profit']) {
            return true;
        }

        // Check for B2B transactions in some countries
        if (isset($options['is_b2b']) && $options['is_b2b']) {
            if (in_array($countryCode, ['DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'IE', 'LU', 'FI', 'SE', 'DK', 'PL', 'CZ', 'HU', 'RO', 'BG', 'HR', 'SI', 'SK', 'EE', 'LV', 'LT', 'MT', 'CY', 'GR'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get exemption reason
     */
    protected function getExemptionReason($countryCode, $stateCode = null, $options = [])
    {
        if (isset($options['tax_exempt_number'])) {
            return 'Tax exempt organization';
        }

        if (isset($options['is_educational']) && $options['is_educational']) {
            return 'Educational institution';
        }

        if (isset($options['is_government']) && $options['is_government']) {
            return 'Government entity';
        }

        if (isset($options['is_non_profit']) && $options['is_non_profit']) {
            return 'Non-profit organization';
        }

        if (isset($options['is_b2b']) && $options['is_b2b']) {
            return 'B2B transaction';
        }

        return 'Unknown exemption';
    }

    /**
     * Calculate tax for multiple items
     */
    public function calculateTaxForItems($items, $countryCode, $stateCode = null, $options = [])
    {
        $totalTax = 0;
        $itemizedTax = [];

        foreach ($items as $item) {
            $itemTax = $this->calculateTax($item['price'], $countryCode, $stateCode, $options);
            $totalTax += $itemTax['tax_amount'];
            $itemizedTax[] = [
                'item_id' => $item['id'] ?? null,
                'name' => $item['name'] ?? 'Unknown Item',
                'price' => $item['price'],
                'tax_amount' => $itemTax['tax_amount'],
                'total_price' => $itemTax['total_amount']
            ];
        }

        return [
            'items' => $itemizedTax,
            'subtotal' => array_sum(array_column($items, 'price')),
            'total_tax' => round($totalTax, 2),
            'grand_total' => round(array_sum(array_column($items, 'price')) + $totalTax, 2),
            'country_code' => $countryCode,
            'state_code' => $stateCode,
            'tax_type' => $this->getTaxType($countryCode, $stateCode)
        ];
    }

    /**
     * Get tax rates for all countries
     */
    public function getAllTaxRates()
    {
        return Cache::remember('all_tax_rates', $this->cacheDuration, function () {
            return TaxRate::with(['country', 'state'])
                ->where('is_active', true)
                ->orderBy('country_code')
                ->orderBy('state_code')
                ->get()
                ->groupBy('country_code');
        });
    }

    /**
     * Update tax rate
     */
    public function updateTaxRate($countryCode, $stateCode, $rate, $isActive = true)
    {
        $taxRate = TaxRate::updateOrCreate(
            [
                'country_code' => $countryCode,
                'state_code' => $stateCode
            ],
            [
                'rate' => $rate,
                'is_active' => $isActive,
                'updated_at' => now()
            ]
        );

        // Clear cache
        $this->clearTaxRateCache($countryCode, $stateCode);

        return $taxRate;
    }

    /**
     * Clear tax rate cache
     */
    protected function clearTaxRateCache($countryCode, $stateCode = null)
    {
        $cacheKey = "tax_rate_{$countryCode}_{$stateCode}";
        Cache::forget($cacheKey);
        Cache::forget('all_tax_rates');
    }

    /**
     * Validate tax information
     */
    public function validateTaxInfo($countryCode, $stateCode = null)
    {
        $errors = [];

        // Validate country code
        if (!Country::where('code', $countryCode)->exists()) {
            $errors[] = "Invalid country code: {$countryCode}";
        }

        // Validate state code if provided
        if ($stateCode && !State::where('country_code', $countryCode)->where('code', $stateCode)->exists()) {
            $errors[] = "Invalid state code: {$stateCode} for country: {$countryCode}";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get tax summary for reporting
     */
    public function getTaxSummary($startDate, $endDate, $countryCode = null)
    {
        $query = TaxRate::query();

        if ($countryCode) {
            $query->where('country_code', $countryCode);
        }

        $taxRates = $query->where('is_active', true)->get();

        $summary = [];
        foreach ($taxRates as $taxRate) {
            $summary[] = [
                'country_code' => $taxRate->country_code,
                'state_code' => $taxRate->state_code,
                'tax_rate' => $taxRate->rate,
                'tax_type' => $this->getTaxType($taxRate->country_code, $taxRate->state_code),
                'is_active' => $taxRate->is_active,
                'last_updated' => $taxRate->updated_at
            ];
        }

        return $summary;
    }
} 