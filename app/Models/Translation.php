<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Translation extends Model
{
    use HasFactory;

    protected $fillable = [
        'language_id',
        'key',
        'value',
        'group',
        'is_html'
    ];

    protected $casts = [
        'is_html' => 'boolean'
    ];

    /**
     * Get the language that owns the translation
     */
    public function language()
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Scope for specific group
     */
    public function scopeGroup($query, $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Scope for specific key
     */
    public function scopeKey($query, $key)
    {
        return $query->where('key', $key);
    }

    /**
     * Get translation value
     */
    public function getValueAttribute($value)
    {
        if ($this->is_html) {
            return $value;
        }
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Set translation value
     */
    public function setValueAttribute($value)
    {
        if ($this->is_html) {
            $this->attributes['value'] = $value;
        } else {
            $this->attributes['value'] = strip_tags($value);
        }
    }

    /**
     * Check if translation is HTML
     */
    public function isHTML()
    {
        return $this->is_html;
    }

    /**
     * Get translation group
     */
    public function getGroupAttribute($value)
    {
        return $value ?: 'general';
    }
} 