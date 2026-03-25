<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleChunk extends Model
{
    /** @use HasFactory<\Database\Factories\ArticleChunkFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
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

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
