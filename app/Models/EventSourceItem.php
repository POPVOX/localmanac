<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSourceItem extends Model
{
    /** @use HasFactory<\Database\Factories\EventSourceItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_source_id',
        'external_id',
        'source_url',
        'raw_payload',
        'fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'raw_payload' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function eventSource(): BelongsTo
    {
        return $this->belongsTo(EventSource::class);
    }
}
