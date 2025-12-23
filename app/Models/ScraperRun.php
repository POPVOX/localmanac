<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScraperRun extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scraper_id',
        'city_id',
        'status',
        'error_message',
        'started_at',
        'finished_at',
        'items_found',
        'items_created',
        'items_updated',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    public function scraper(): BelongsTo
    {
        return $this->belongsTo(Scraper::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
