<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CivicAction extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'city_id',
        'kind',
        'title',
        'subtitle',
        'description',
        'url',
        'cta_label',
        'starts_at',
        'ends_at',
        'location',
        'badge_text',
        'status',
        'source',
        'source_payload',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'source_payload' => 'array',
            'position' => 'integer',
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
