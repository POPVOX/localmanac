<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ChatSourceIngestionRun extends Model
{
    /** @use HasFactory<\Database\Factories\ChatSourceIngestionRunFactory> */
    use HasFactory;

    public const STALE_QUEUED_MINUTES = 360;

    public const STALE_RUNNING_MINUTES = 30;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'chat_source_id',
        'status',
        'started_at',
        'finished_at',
        'pages_found',
        'pages_changed',
        'pages_embedded',
        'error_class',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pages_found' => 'integer',
            'pages_changed' => 'integer',
            'pages_embedded' => 'integer',
        ];
    }

    public function chatSource(): BelongsTo
    {
        return $this->belongsTo(ChatSource::class);
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

    public function isFreshActive(): bool
    {
        return match ($this->status) {
            'queued' => $this->created_at?->greaterThanOrEqualTo($this->queuedStaleCutoff()) ?? false,
            'running' => $this->updated_at?->greaterThanOrEqualTo($this->runningStaleCutoff()) ?? false,
            default => false,
        };
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
