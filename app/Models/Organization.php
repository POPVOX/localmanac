<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'name',
        'slug',
        'type',
        'website',
        'description',
        'credibility_score',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'credibility_score' => 'integer',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function scrapers(): HasMany
    {
        return $this->hasMany(Scraper::class);
    }
}
