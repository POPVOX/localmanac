<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSourcePage extends Model
{
    /** @use HasFactory<\Database\Factories\ChatSourcePageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'chat_source_id',
        'url',
        'canonical_url',
        'title',
        'content_type',
        'renderer',
        'status_code',
        'fetch_duration_ms',
        'content_text',
        'content_length',
        'content_hash',
        'fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'fetch_duration_ms' => 'integer',
            'content_length' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ChatSource::class, 'chat_source_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ChatSourceChunk::class, 'chat_source_page_id');
    }
}
