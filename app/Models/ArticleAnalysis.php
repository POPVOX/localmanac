<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAnalysis extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'score_version',
        'status',
        'heuristic_scores',
        'llm_scores',
        'final_scores',
        'civic_relevance_score',
        'model',
        'prompt_version',
        'confidence',
        'last_scored_at',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'heuristic_scores' => 'array',
        'llm_scores' => 'array',
        'final_scores' => 'array',
        'civic_relevance_score' => 'decimal:3',
        'confidence' => 'decimal:3',
        'last_scored_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
