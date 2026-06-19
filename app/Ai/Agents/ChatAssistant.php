<?php

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class ChatAssistant implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations {
        messages as rememberedMessages;
    }

    /**
     * @var array<int, Message>
     */
    protected array $contextMessages = [];

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the list of messages comprising the conversation so far.
     */
    public function messages(): iterable
    {
        $remembered = $this->rememberedMessages();

        return ! empty($remembered)
            ? $remembered
            : $this->contextMessages;
    }

    /**
     * Set transient context messages (used for anonymous sessions).
     */
    public function withContextMessages(iterable $messages): self
    {
        $this->contextMessages = collect($messages)
            ->map(fn ($message) => Message::tryFrom($message))
            ->values()
            ->all();

        return $this;
    }
}
