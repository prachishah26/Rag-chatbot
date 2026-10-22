<?php

namespace App\Services\Rag\Conversation;

/** What a chat message is: small talk, or a document question that stands on its own. */
final readonly class RoutedMessage
{
    private function __construct(
        public bool $isChat,
        public string $question,
    ) {}

    public static function chat(string $message): self
    {
        return new self(true, $message);
    }

    public static function document(string $question): self
    {
        return new self(false, $question);
    }
}
