<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleOpportunity extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'kind',
        'title',
        'starts_at',
        'ends_at',
        'location',
        'url',
        'notes',
        'source',
        'confidence',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'confidence' => 'decimal:3',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
