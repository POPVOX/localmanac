<?php

namespace App\Models;

use Database\Factories\EventIngestionRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EventIngestionRun extends Model
{
    /** @use HasFactory<EventIngestionRunFactory> */
    use HasFactory;

    public const STALE_QUEUED_MINUTES = 10;

    public const STALE_RUNNING_MINUTES = 10;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_source_id',
        'status',
        'started_at',
        'finished_at',
        'items_found',
        'items_written',
        'error_class',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function eventSource(): BelongsTo
    {
        return $this->belongsTo(EventSource::class);
    }

    public function scopeFreshActive(Builder $query): Builder
    {
        $queuedCutoff = $this->queuedStaleCutoff();
        $runningCutoff = $this->runningStaleCutoff();

        return $query->where(function (Builder $nested) use ($queuedCutoff, $runningCutoff): void {
            $nested
                ->where(function (Builder $queued) use ($queuedCutoff): void {
                    $queued
                        ->where('status', 'queued')
                        ->where('created_at', '>=', $queuedCutoff);
                })
                ->orWhere(function (Builder $running) use ($runningCutoff): void {
                    $running
                        ->where('status', 'running')
                        ->where('updated_at', '>=', $runningCutoff);
                });
        });
    }

    public function scopeStaleActive(Builder $query): Builder
    {
        $queuedCutoff = $this->queuedStaleCutoff();
        $runningCutoff = $this->runningStaleCutoff();

        return $query->where(function (Builder $nested) use ($queuedCutoff, $runningCutoff): void {
            $nested
                ->where(function (Builder $queued) use ($queuedCutoff): void {
                    $queued
                        ->where('status', 'queued')
                        ->where('created_at', '<', $queuedCutoff);
                })
                ->orWhere(function (Builder $running) use ($runningCutoff): void {
                    $running
                        ->where('status', 'running')
                        ->where('updated_at', '<', $runningCutoff);
                });
        });
    }

    public static function expireStaleActive(): int
    {
        return static::query()
            ->staleActive()
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_class' => null,
                'error_message' => __('Run timed out before the worker started.'),
                'updated_at' => now(),
            ]);
    }

    private function queuedStaleCutoff(): Carbon
    {
        return now()->subMinutes(self::STALE_QUEUED_MINUTES);
    }

    private function runningStaleCutoff(): Carbon
    {
        return now()->subMinutes(self::STALE_RUNNING_MINUTES);
    }
}
