<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscussionReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'discussion_id',
        'user_id',
        'parent_id',
        'content',
        'is_solution',
        'is_edited',
        'edited_at',
        'edited_by',
        'metadata'
    ];

    protected $casts = [
        'is_solution' => 'boolean',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
        'metadata' => 'array'
    ];

    /**
     * Get the discussion that owns the reply.
     */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /**
     * Get the user that owns the reply.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent reply if this is a nested reply.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DiscussionReply::class, 'parent_id');
    }

    /**
     * Get the nested replies to this reply.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(DiscussionReply::class, 'parent_id');
    }

    /**
     * Get the editor of this reply.
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * Get the likes for this reply.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(DiscussionLike::class, 'reply_id');
    }

    /**
     * Check if reply is a solution.
     */
    public function isSolution(): bool
    {
        return $this->is_solution;
    }

    /**
     * Check if reply is edited.
     */
    public function isEdited(): bool
    {
        return $this->is_edited;
    }

    /**
     * Check if reply is a nested reply.
     */
    public function isNested(): bool
    {
        return !is_null($this->parent_id);
    }

    /**
     * Mark reply as solution.
     */
    public function markAsSolution(): void
    {
        // Remove solution mark from other replies in the same discussion
        $this->discussion->replies()->where('is_solution', true)->update(['is_solution' => false]);
        
        $this->update(['is_solution' => true]);
    }

    /**
     * Get formatted content with markdown support.
     */
    public function getFormattedContentAttribute(): string
    {
        // Basic markdown support
        $content = $this->content;
        
        // Bold
        $content = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $content);
        
        // Italic
        $content = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $content);
        
        // Code
        $content = preg_replace('/`(.*?)`/', '<code>$1</code>', $content);
        
        // Links
        $content = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank">$1</a>', $content);
        
        // Line breaks
        $content = nl2br($content);
        
        return $content;
    }

    /**
     * Get reply depth level.
     */
    public function getDepthAttribute(): int
    {
        $depth = 0;
        $parent = $this->parent;
        
        while ($parent) {
            $depth++;
            $parent = $parent->parent;
        }
        
        return $depth;
    }

    /**
     * Scope to get solution replies.
     */
    public function scopeSolutions($query)
    {
        return $query->where('is_solution', true);
    }

    /**
     * Scope to get top-level replies.
     */
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope to get replies by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get recent replies.
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}










