<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasFactory;

    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'original_name',
        'path',
        'mime_type',
        'size',
        'status',
        'error_message',
        'chunk_count',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'processed_at' => 'datetime',
            'size' => 'integer',
            'chunk_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A document without an owner is shared: every user can search it, no user can delete it. */
    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    /** Owners delete their own documents; admins delete shared ones. */
    public function canBeDeletedBy(User $user): bool
    {
        return $this->isShared() ? $user->is_admin : $this->user_id === $user->id;
    }

    /** The user's own documents plus the shared ones. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhereNull('user_id'));
    }

    /** Fully indexed, so a chat can search it. */
    public function scopeSearchable(Builder $query): void
    {
        $query->where('status', self::COMPLETED);
    }
}
