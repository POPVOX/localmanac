<?php

namespace App\Services\Chat\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class StreamingChatAnswerAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(
        public iterable $tools = [],
    ) {}

    public function instructions(): string
    {
        return 'You are a civic information assistant. Use tools for retrieval and return plain text only.';
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    protected function maxConversationMessages(): int
    {
        return (int) config('chat.memory_max_messages', 100);
    }
}
