<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class City extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'state',
        'country',
        'timezone',
        'chat_access_code_hash',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'chat_access_code_hash',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function hasChatAccessCode(): bool
    {
        return is_string($this->chat_access_code_hash)
            && $this->chat_access_code_hash !== '';
    }

    public function matchesChatAccessCode(string $code): bool
    {
        return $this->hasChatAccessCode()
            && Hash::check(trim($code), $this->chat_access_code_hash);
    }

    public static function findByChatAccessCode(string $code): ?self
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return static::query()
            ->whereNotNull('chat_access_code_hash')
            ->get()
            ->first(fn (self $city): bool => $city->matchesChatAccessCode($code));
    }

    public function eventSources(): HasMany
    {
        return $this->hasMany(EventSource::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function scrapers(): HasMany
    {
        return $this->hasMany(Scraper::class);
    }

    public function scraperRuns(): HasMany
    {
        return $this->hasMany(ScraperRun::class);
    }

    public function chatSources(): HasMany
    {
        return $this->hasMany(ChatSource::class);
    }

    public function siteFeedbackEntries(): HasMany
    {
        return $this->hasMany(SiteFeedback::class);
    }
}
