<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class CityAccessCode extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'city_id',
        'label',
        'description',
        'code_hash',
        'lookup_digest',
        'is_active',
        'is_legacy',
        'expires_at',
        'last_redeemed_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'code_hash',
        'lookup_digest',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_legacy' => 'boolean',
            'expires_at' => 'datetime',
            'last_redeemed_at' => 'datetime',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CityAccessCodeRedemption::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function isAvailable(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function matches(string $plainTextCode): bool
    {
        return $this->isAvailable()
            && Hash::check(trim($plainTextCode), $this->code_hash);
    }

    public function matchesRegardlessOfAvailability(string $plainTextCode): bool
    {
        return Hash::check(trim($plainTextCode), $this->code_hash);
    }

    public static function findMatchingAvailable(string $plainTextCode, ?int $cityId = null): ?self
    {
        $plainTextCode = trim($plainTextCode);

        if ($plainTextCode === '') {
            return null;
        }

        $query = static::query()
            ->with('city')
            ->available()
            ->when($cityId, fn (Builder $query) => $query->where('city_id', $cityId));

        $indexedMatch = (clone $query)
            ->where('lookup_digest', static::lookupDigest($plainTextCode))
            ->first();

        if ($indexedMatch?->matches($plainTextCode)) {
            return $indexedMatch;
        }

        return $query
            ->whereNull('lookup_digest')
            ->get()
            ->first(fn (self $code): bool => $code->matches($plainTextCode));
    }

    public static function plainTextCodeExists(string $plainTextCode): bool
    {
        $plainTextCode = trim($plainTextCode);

        if ($plainTextCode === '') {
            return false;
        }

        if (static::query()->where('lookup_digest', static::lookupDigest($plainTextCode))->exists()) {
            return true;
        }

        return static::query()
            ->whereNull('lookup_digest')
            ->get()
            ->contains(fn (self $code): bool => $code->matchesRegardlessOfAvailability($plainTextCode));
    }

    public static function lookupDigest(string $plainTextCode): string
    {
        return hash_hmac('sha256', trim($plainTextCode), (string) config('app.key'));
    }
}
