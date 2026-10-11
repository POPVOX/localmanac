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
        return implode("\n", [
            'You are a civic information assistant. Use tools for retrieval and return plain text only.',
            'Source edition dates constrain what you can claim today. A recent fetch does not update an old document. If a quoted fee is supported only by an older edition, name that edition date and explicitly say the current fee has not been verified. For example: "The [edition date] guide lists [amount]; I have not verified the current fee." Never present that amount as a confirmed current payment requirement.',
            'A fee table does not establish application steps. Describe only the steps actually supported by the retrieved evidence.',
        ]);
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
