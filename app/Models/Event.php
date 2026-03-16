<?php

namespace App\Models;

use App\Models\Concerns\NormalizesTimestampsToUtc;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    use NormalizesTimestampsToUtc;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'title',
        'starts_at',
        'ends_at',
        'all_day',
        'location_name',
        'location_address',
        'description',
        'event_url',
        'source_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'all_day' => 'boolean',
    ];

    protected function startsAt(): Attribute
    {
        return $this->utcTimestampAttribute();
    }

    protected function endsAt(): Attribute
    {
        return $this->utcTimestampAttribute();
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function sourceItems(): HasMany
    {
        return $this->hasMany(EventSourceItem::class);
    }
}
