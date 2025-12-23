<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Article extends Model
{
    use HasFactory;
    use Searchable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'scraper_id',
        'title',
        'summary',
        'published_at',
        'content_type',
        'status',
        'canonical_url',
        'content_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function body(): HasOne
    {
        return $this->hasOne(ArticleBody::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(ArticleAnalysis::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(ArticleOpportunity::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ArticleSource::class);
    }

    public function scraper(): BelongsTo
    {
        return $this->belongsTo(Scraper::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function searchableAs(): string
    {
        return 'articles';
    }

    public function primarySourceUrl(): ?string
    {
        if ($this->relationLoaded('sources')) {
            $source = $this->sources->first();
        } else {
            $source = $this->sources()->oldest()->first();
        }

        return $source?->source_url;
    }

    /**
     * @return array{
     *     id: int,
     *     city_id: int,
     *     title: string|null,
     *     summary: string|null,
     *     body: string,
     *     published_at: string|null,
     *     created_at: string|null,
     *     organization_id: int|null,
     *     scraper_id: int|null,
     *     extraction_status: string|null,
     *     source_url: string|null
     * }
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['body', 'sources', 'scraper']);

        $body = Str::limit($this->body?->cleaned_text ?? '', 20000, '');

        return [
            'id' => (int) $this->id,
            'city_id' => $this->city_id === null ? null : (int) $this->city_id,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $body,
            'published_at' => $this->published_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
            'organization_id' => $this->scraper?->organization_id === null
                ? null
                : (int) $this->scraper->organization_id,
            'scraper_id' => $this->scraper_id === null ? null : (int) $this->scraper_id,
            'extraction_status' => $this->body?->extraction_status,
            'source_url' => $this->primarySourceUrl(),
        ];
    }
}
