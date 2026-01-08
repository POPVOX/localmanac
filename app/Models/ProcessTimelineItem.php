<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessTimelineItem extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'city_id',
        'key',
        'label',
        'status',
        'date',
        'has_time',
        'badge_text',
        'note',
        'evidence_json',
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
            'date' => 'datetime',
            'has_time' => 'boolean',
            'evidence_json' => 'array',
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
