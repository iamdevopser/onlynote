<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'flag',
        'is_active',
        'is_default',
        'direction',
        'date_format',
        'time_format'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean'
    ];

    /**
     * Get translations for this language
     */
    public function translations()
    {
        return $this->hasMany(Translation::class);
    }

    /**
     * Get active languages
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get default language
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Check if language is RTL
     */
    public function isRTL()
    {
        return $this->direction === 'rtl';
    }

    /**
     * Get flag URL
     */
    public function getFlagUrlAttribute()
    {
        return asset("images/flags/{$this->flag}.png");
    }

    /**
     * Get formatted date
     */
    public function formatDate($date)
    {
        if (!$date) return '';
        
        $date = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);
        return $date->format($this->date_format);
    }

    /**
     * Get formatted time
     */
    public function formatTime($time)
    {
        if (!$time) return '';
        
        $time = $time instanceof \Carbon\Carbon ? $time : \Carbon\Carbon::parse($time);
        return $time->format($this->time_format);
    }

    /**
     * Get language direction class
     */
    public function getDirectionClassAttribute()
    {
        return $this->isRTL() ? 'rtl' : 'ltr';
    }
} 