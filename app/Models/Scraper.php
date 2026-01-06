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

    public const DEFAULT_RUN_AT = '08:00';

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
        'frequency',
        'run_at',
        'run_day_of_week',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'config' => 'array',
        'is_enabled' => 'boolean',
        'frequency' => 'string',
        'run_at' => 'string',
        'run_day_of_week' => 'integer',
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

    public function latestSuccessfulRun(): HasOne
    {
        return $this->hasOne(ScraperRun::class)
            ->where('status', 'success')
            ->whereNotNull('finished_at')
            ->latestOfMany('finished_at');
    }
}
