<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleSource extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'city_id',
        'article_id',
        'organization_id',
        'source_url',
        'source_type',
        'source_uid',
        'accessed_at',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];
}
