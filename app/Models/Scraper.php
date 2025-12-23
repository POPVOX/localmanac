<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Scraper extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'organization_id',
        'name',
        'slug',
        'type',
        'source_url',
        'config',
        'is_enabled',
        'schedule_cron',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'config' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScraperRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(ScraperRun::class)->latestOfMany();
    }
}
