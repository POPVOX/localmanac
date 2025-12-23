<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleIssueArea extends Model
{
    /** @use HasFactory<\Database\Factories\ArticleIssueAreaFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'issue_area_id',
        'confidence',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function issueArea(): BelongsTo
    {
        return $this->belongsTo(IssueArea::class);
    }
}
