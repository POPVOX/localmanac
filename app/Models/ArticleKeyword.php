<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleKeyword extends Model
{
    /** @use HasFactory<\Database\Factories\ArticleKeywordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'keyword_id',
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

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }
}
