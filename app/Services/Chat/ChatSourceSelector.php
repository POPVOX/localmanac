<?php

namespace App\Services\Chat;

use App\Models\ChatSource;
use Illuminate\Support\Collection;
use Throwable;

/**
 * IMPORTANT ARCHITECTURE RULES
 *
 * - This class is retrieval only.
 * - Do NOT add answer routing, prompt branching, or synthesis strategy here.
 * - It may rank and fall back across sources, but it must not decide how chat answers are produced.
 */
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
            try {
                $sources = ChatSource::search($question)
                    ->where('city_id', $cityId)
                    ->where('is_active', true)
                    ->take($limit)
                    ->get();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($sources->count() < $limit) {
            $sources = $sources
                ->merge(
                    ChatSource::query()
                        ->where('city_id', $cityId)
                        ->where('is_active', true)
                        ->orderByDesc('priority')
                        ->orderBy('id')
                        ->take(max($limit * 2, $limit))
                        ->get()
                );
        }

        return $sources
            ->unique('id')
            ->take($limit)
            ->values();
    }
}
