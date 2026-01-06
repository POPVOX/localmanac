<?php

namespace App\Services\Ingestion;

use App\Models\Scraper;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ScraperScheduler
{
    /**
     * @return Collection<int, Scraper>
     */
    public function dueScrapers(CarbonInterface $nowUtc): Collection
    {
        $immutableNow = $nowUtc instanceof CarbonImmutable ? $nowUtc : CarbonImmutable::instance($nowUtc);

        $scrapers = Scraper::query()
            ->with(['city', 'latestSuccessfulRun'])
            ->where('is_enabled', true)
            ->whereIn('type', ['rss', 'html'])
            ->whereDoesntHave('runs', function ($query) {
                $query->whereIn('status', ['queued', 'running']);
            })
            ->get();

        return $scrapers
            ->filter(fn (Scraper $scraper): bool => $this->isDue($scraper, $immutableNow))
            ->values();
    }

    private function isDue(Scraper $scraper, CarbonImmutable $nowUtc): bool
    {
        return match ($scraper->frequency ?? 'daily') {
            'hourly' => $this->isHourlyDue($scraper, $nowUtc),
            'daily' => $this->isDailyDue($scraper, $nowUtc),
            'weekly' => $this->isWeeklyDue($scraper, $nowUtc),
            default => false,
        };
    }

    private function isHourlyDue(Scraper $scraper, CarbonImmutable $nowUtc): bool
    {
        $lastSuccess = $this->lastSuccessfulFinishedAtUtc($scraper);

        if (! $lastSuccess) {
            return true;
        }

        return $nowUtc->greaterThanOrEqualTo($lastSuccess->addMinutes(60));
    }

    private function isDailyDue(Scraper $scraper, CarbonImmutable $nowUtc): bool
    {
        $timezone = $this->timezoneForScraper($scraper);
        $localNow = $nowUtc->setTimezone($timezone);
        $runAt = $this->runAtForDate($localNow, $scraper->run_at);

        if (! $runAt || $localNow->lessThan($runAt)) {
            return false;
        }

        $lastSuccessLocal = $this->lastSuccessfulFinishedAtLocal($scraper, $timezone);

        if ($lastSuccessLocal && $lastSuccessLocal->isSameDay($localNow)) {
            return false;
        }

        return true;
    }

    private function isWeeklyDue(Scraper $scraper, CarbonImmutable $nowUtc): bool
    {
        $timezone = $this->timezoneForScraper($scraper);
        $localNow = $nowUtc->setTimezone($timezone);
        $runAt = $this->runAtForDate($localNow, $scraper->run_at);

        if (! $runAt || $scraper->run_day_of_week === null) {
            return false;
        }

        if ($localNow->dayOfWeek !== $scraper->run_day_of_week || $localNow->lessThan($runAt)) {
            return false;
        }

        $lastSuccessLocal = $this->lastSuccessfulFinishedAtLocal($scraper, $timezone);

        if (! $lastSuccessLocal) {
            return true;
        }

        return $this->startOfWeek($lastSuccessLocal)->lessThan($this->startOfWeek($localNow));
    }

    private function lastSuccessfulFinishedAtUtc(Scraper $scraper): ?CarbonImmutable
    {
        $finishedAt = $scraper->latestSuccessfulRun?->finished_at;

        if (! $finishedAt) {
            return null;
        }

        return $finishedAt instanceof CarbonImmutable
            ? $finishedAt
            : CarbonImmutable::instance($finishedAt);
    }

    private function lastSuccessfulFinishedAtLocal(Scraper $scraper, string $timezone): ?CarbonImmutable
    {
        return $this->lastSuccessfulFinishedAtUtc($scraper)?->setTimezone($timezone);
    }

    private function timezoneForScraper(Scraper $scraper): string
    {
        return $scraper->city?->timezone ?? config('app.timezone', 'UTC');
    }

    private function runAtForDate(CarbonImmutable $localNow, ?string $runAt): ?CarbonImmutable
    {
        $parsedTime = $this->parseRunAt($runAt);

        if (! $parsedTime) {
            $parsedTime = $this->parseRunAt(Scraper::DEFAULT_RUN_AT);
        }

        if (! $parsedTime) {
            return null;
        }

        return $localNow->setTime($parsedTime['hour'], $parsedTime['minute']);
    }

    /**
     * @return array{hour: int, minute: int}|null
     */
    private function parseRunAt(?string $runAt): ?array
    {
        if ($runAt === null || trim($runAt) === '') {
            return null;
        }

        $parts = explode(':', trim($runAt));

        if (count($parts) < 2) {
            return null;
        }

        $hour = (int) $parts[0];
        $minute = (int) $parts[1];

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return ['hour' => $hour, 'minute' => $minute];
    }

    private function startOfWeek(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfWeek(CarbonImmutable::SUNDAY);
    }
}
