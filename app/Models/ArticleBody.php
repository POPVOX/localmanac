<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleBody extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'raw_text',
        'cleaned_text',
        'raw_html',
        'lang',
        'extracted_at',
        'extraction_status',
        'extraction_error',
        'extraction_meta',
    ];

    protected $casts = [
        'extracted_at' => 'datetime',
        'extraction_meta' => 'array',
    ];
}
