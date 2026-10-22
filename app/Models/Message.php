<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    // Saving a message bumps the conversation, so the sidebar lists recent chats first.
    protected $touches = ['conversation'];

    protected $fillable = [
        'role',
        'content',
        'sources',
        'quotes',
    ];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'quotes' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
