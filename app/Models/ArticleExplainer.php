<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleExplainer extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'city_id',
        'whats_happening',
        'why_it_matters',
        'key_details',
        'what_to_watch',
        'evidence_json',
        'source',
        'source_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_details' => 'array',
            'what_to_watch' => 'array',
            'evidence_json' => 'array',
            'source_payload' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
