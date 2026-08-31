<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ScraperRun extends Model
{
    use HasFactory;

    /**
     * @var array<string, list<string>>
     */
    private const SOURCE_ISSUE_PATTERNS = [
        'unreachable' => [
            'failed to fetch listing page',
            'failed to fetch rss feed',
            'could not resolve host',
            'name or service not known',
            'nodename nor servname provided',
            'http 404',
            'http 410',
        ],
        'blocked' => [
            'blocked by anti-bot',
            'http 401',
            'http 403',
        ],
        'configuration' => [
            'missing rss feed url',
            'source_url must exist',
            'scraper profile must be',
            'scraper list/article config must exist',
            'link_selector must exist',
            'content_selector must exist',
            'current node list is empty',
            'unsupported scraper type',
        ],
    ];

    public const STALE_QUEUED_MINUTES = 10;

    public const STALE_RUNNING_MINUTES = 30;

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

    public function sourceNeedsUpdate(): bool
    {
        return $this->sourceIssueType() !== null;
    }

    public function sourceIssueSummary(): ?string
    {
        return match ($this->sourceIssueType()) {
            'unreachable' => __('The source URL could not be reached. Confirm that it still exists or replace it.'),
            'blocked' => __('The source now blocks automated access. Update the renderer, credentials, or source URL.'),
            'configuration' => __('The saved scraper configuration is incomplete or no longer matches the source.'),
            default => null,
        };
    }

    private function sourceIssueType(): ?string
    {
        if ($this->status !== 'failed' || blank($this->error_message)) {
            return null;
        }

        $errorMessage = Str::lower((string) $this->error_message);

        foreach (self::SOURCE_ISSUE_PATTERNS as $type => $patterns) {
            if (Str::contains($errorMessage, $patterns)) {
                return $type;
            }
        }

        return null;
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
