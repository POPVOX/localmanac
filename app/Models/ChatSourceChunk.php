<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatSourceChunk extends Model
{
    /** @use HasFactory<\Database\Factories\ChatSourceChunkFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'chat_source_page_id',
        'chunk_index',
        'content',
        'content_length',
        'content_hash',
        'embedding_model',
        'embedding',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'content_length' => 'integer',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ChatSourcePage::class, 'chat_source_page_id');
    }
}
