<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class ChatSource extends Model
{
    use HasFactory;
    use Searchable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'name',
        'source_url',
        'description',
        'tags',
        'priority',
        'is_active',
        'link_follow_mode',
        'link_limit',
        'crawl_renderer',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'link_limit' => 'integer',
            'crawl_renderer' => 'string',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(ChatSourcePage::class);
    }

    public function searchableAs(): string
    {
        return 'chat_sources';
    }

    /**
     * @return array{
     *     id: int,
     *     city_id: int,
     *     name: string,
     *     description: string|null,
     *     tags: array<int, string>,
     *     source_url: string,
     *     priority: int,
     *     is_active: bool
     * }
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'city_id' => (int) $this->city_id,
            'name' => $this->name,
            'description' => $this->description,
            'tags' => $this->tags ?? [],
            'source_url' => $this->source_url,
            'priority' => (int) $this->priority,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
