<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'title',
        'description',
        'resource_type',
        'file_path',
        'file_size',
        'mime_type',
        'is_downloadable',
        'order',
        'metadata'
    ];

    protected $casts = [
        'is_downloadable' => 'boolean',
        'metadata' => 'array'
    ];

    /**
     * Get the assignment that owns the resource.
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * Get formatted file size.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        if (!$this->file_size) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * Check if resource is downloadable.
     */
    public function isDownloadable(): bool
    {
        return $this->is_downloadable && $this->file_path;
    }

    /**
     * Get resource icon based on type.
     */
    public function getResourceIconAttribute(): string
    {
        return match($this->resource_type) {
            'file' => 'fas fa-file',
            'link' => 'fas fa-link',
            'text' => 'fas fa-align-left',
            default => 'fas fa-file'
        };
    }

    /**
     * Get resource type badge.
     */
    public function getResourceTypeBadgeAttribute(): string
    {
        return match($this->resource_type) {
            'file' => 'bg-primary',
            'link' => 'bg-success',
            'text' => 'bg-info',
            default => 'bg-secondary'
        };
    }
}










