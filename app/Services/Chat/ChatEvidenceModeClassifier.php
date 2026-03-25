<?php

namespace App\Services\Chat;

use App\Services\Chat\Event\EventIntentDetector;

class ChatEvidenceModeClassifier
{
    public const REFERENCE = 'reference';

    public const EVENTS = 'events';

    public const UPDATES = 'updates';

    public function __construct(
        private readonly EventIntentDetector $eventIntentDetector,
    ) {}

    public function classify(string $question): string
    {
        $normalizedQuestion = $this->normalize($question);

        if ($normalizedQuestion === '') {
            return self::REFERENCE;
        }

        if ($this->isUpdatesQuery($normalizedQuestion) && ! $this->containsExplicitEventAnchors($normalizedQuestion)) {
            return self::UPDATES;
        }

        if ($this->containsExplicitEventAnchors($normalizedQuestion) || $this->eventIntentDetector->isEventIntent($question)) {
            return self::EVENTS;
        }

        return self::REFERENCE;
    }

    private function isUpdatesQuery(string $question): bool
    {
        return preg_match(
            '/\b(what(?:\'s| is)? new|what changed|recent updates?|local updates?|service alerts?|alerts?|disruptions?|new permits?|rezonings?|projects?|recent decisions?|deadlines?|approved|filed|summary|summarize|digest|important updates?)\b/u',
            $question
        ) === 1;
    }

    private function containsExplicitEventAnchors(string $question): bool
    {
        foreach ([
            'meeting',
            'meetings',
            'agenda',
            'agendas',
            'event',
            'events',
            'city council',
            'board',
            'boards',
            'commission',
            'commissions',
            'public meeting',
            'public meetings',
            'weekend',
            'this weekend',
            'next weekend',
        ] as $needle) {
            if (str_contains($question, $needle)) {
                return true;
            }
        }

        return preg_match('/\bnext\s+(?:7|14)\s+days\b/u', $question) === 1;
    }

    private function normalize(string $question): string
    {
        $question = mb_strtolower($question);
        $question = preg_replace('/\s+/', ' ', $question) ?? '';

        return trim($question);
    }
}
