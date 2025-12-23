<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Claim extends Model
{
    /** @use HasFactory<\Database\Factories\ClaimFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'article_id',
        'claim_type',
        'subject_type',
        'subject_id',
        'value_json',
        'evidence_json',
        'confidence',
        'source',
        'model',
        'prompt_version',
        'status',
        'value_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'evidence_json' => 'array',
            'confidence' => 'float',
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

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
