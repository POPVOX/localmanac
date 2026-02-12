<?php

namespace App\Services\Chat\Tools;

use App\Models\City;
use App\Services\Chat\Event\EventSearchService;
use App\Services\Chat\Event\EventWindowResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class EventSearchTool implements Tool
{
    /**
     * @param  array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     is_explicit: bool,
     *     parse_confidence: float
     * }|null  $defaultWindow
     */
    public function __construct(
        private readonly EventSearchService $eventSearchService,
        private readonly EventWindowResolver $eventWindowResolver,
        private readonly City $city,
        private readonly ?array $defaultWindow = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Search local city calendar events for a time window and optional topic keywords.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));
        $timezone = $this->city->timezone ?: config('app.timezone', 'UTC');
        $limit = max(1, min(20, (int) ($request['limit'] ?? (int) config('chat.events.max_results', 8))));

        $window = $this->windowFromRequest($request, $timezone);

        if ($window === null) {
            $window = $this->defaultWindow;
        }

        if ($window === null) {
            $window = $this->eventWindowResolver->resolve($query, $timezone);
        }

        $result = $this->eventSearchService->search(
            city: $this->city,
            window: $window,
            question: $query,
            limit: $limit,
        );

        $payload = [
            'city' => [
                'id' => (int) $this->city->id,
                'name' => $this->city->name,
                'slug' => $this->city->slug,
            ],
            'window' => [
                'start_at' => $result['window']['start_at']->copy()->setTimezone($timezone)->toIso8601String(),
                'end_at' => $result['window']['end_at']->copy()->setTimezone($timezone)->toIso8601String(),
                'label' => $result['window']['label'],
            ],
            'total' => $result['total'],
            'has_more' => $result['has_more'],
            'events' => $result['events'],
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"events":[]}';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'start_date' => $schema->string(),
            'end_date' => $schema->string(),
            'limit' => $schema->integer()->min(1)->max(20),
        ];
    }

    /**
     * @return array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     is_explicit: bool,
     *     parse_confidence: float
     * }|null
     */
    private function windowFromRequest(Request $request, string $timezone): ?array
    {
        $startDate = trim((string) ($request['start_date'] ?? ''));
        $endDate = trim((string) ($request['end_date'] ?? ''));

        if ($startDate === '' || $endDate === '') {
            return null;
        }

        try {
            $start = Carbon::parse($startDate, $timezone)->startOfDay();
            $end = Carbon::parse($endDate, $timezone)->endOfDay();
        } catch (Throwable) {
            return null;
        }

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        return [
            'start_at' => $start,
            'end_at' => $end,
            'label' => $start->format('F j, Y').' to '.$end->format('F j, Y'),
            'is_explicit' => true,
            'parse_confidence' => 1.0,
        ];
    }
}
