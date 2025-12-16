<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
