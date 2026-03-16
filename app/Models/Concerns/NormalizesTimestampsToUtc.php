<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

trait NormalizesTimestampsToUtc
{
    protected function utcTimestampAttribute(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): mixed => $this->normalizeTimestampToUtc($value),
        );
    }

    private function normalizeTimestampToUtc(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy()->setTimezone('UTC');
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->setTimezone('UTC');
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value)->setTimezone('UTC');
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }
}
