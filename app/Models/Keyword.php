<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    /** @use HasFactory<\Database\Factories\KeywordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'name',
        'slug',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function articleKeywords(): HasMany
    {
        return $this->hasMany(ArticleKeyword::class);
    }
}
