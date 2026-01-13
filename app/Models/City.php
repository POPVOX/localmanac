<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'state',
        'country',
        'timezone',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function eventSources(): HasMany
    {
        return $this->hasMany(EventSource::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function scrapers(): HasMany
    {
        return $this->hasMany(Scraper::class);
    }

    public function scraperRuns(): HasMany
    {
        return $this->hasMany(ScraperRun::class);
    }
}
