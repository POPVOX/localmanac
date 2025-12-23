<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleEntity extends Model
{
    /** @use HasFactory<\Database\Factories\ArticleEntityFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'entity_type',
        'entity_id',
        'display_name',
        'confidence',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
