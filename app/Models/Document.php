<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
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
            'processed_at' => 'datetime',
            'size' => 'integer',
            'chunk_count' => 'integer',
        ];
    }
}
