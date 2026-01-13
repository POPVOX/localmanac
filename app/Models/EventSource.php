<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EventSource extends Model
{
    /** @use HasFactory<\Database\Factories\EventSourceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'name',
        'source_type',
        'source_url',
        'config',
        'frequency',
        'is_active',
        'last_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'config' => 'array',
        'frequency' => 'string',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EventSourceItem::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(EventIngestionRun::class)->orderByDesc('created_at');
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(EventIngestionRun::class)->latestOfMany();
    }
}
