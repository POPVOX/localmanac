<?php

namespace App\Services\Chat;

use App\Models\ChatSource;
use Illuminate\Support\Collection;

class ChatSourceSelector
{
    /**
     * @return Collection<int, ChatSource>
     */
    public function select(int $cityId, string $question, ?int $limit = null): Collection
    {
        $limit = $limit ?? (int) config('chat.max_sources', 12);
        $question = trim($question);

        $sources = collect();

        if ($question !== '') {
            $sources = ChatSource::search($question)
                ->where('city_id', $cityId)
                ->where('is_active', true)
                ->take($limit)
                ->get();
        }

        if ($sources->count() < $limit) {
            $fallback = ChatSource::query()
                ->where('city_id', $cityId)
                ->where('is_active', true)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->take($limit)
                ->get();

            $sources = $sources->merge($fallback);
        }

        return $sources
            ->unique('id')
            ->take($limit)
            ->values();
    }
}
