<?php

namespace App\Services\Rag\Conversation;

/** Earlier messages of a conversation as chat-model messages, trimmed to keep prompts small. */
final class ChatHistory
{
    private const MAX_MESSAGE_CHARS = 800;

    /**
     * @param  iterable<array{role: string, content: string}>  $messages  oldest first
     * @return array<int, array{role: string, content: string}>
     */
    public static function trim(iterable $messages): array
    {
        $trimmed = [];

        foreach ($messages as $message) {
            $trimmed[] = [
                'role' => $message['role'] === 'user' ? 'user' : 'assistant',
                'content' => mb_strimwidth((string) $message['content'], 0, self::MAX_MESSAGE_CHARS, '…'),
            ];
        }

        return $trimmed;
    }

    /** The conversation as plain text, for prompts that read it rather than continue it. */
    public static function transcript(array $history): string
    {
        return implode("\n", array_map(
            fn (array $message) => ($message['role'] === 'user' ? 'User: ' : 'Assistant: ').$message['content'],
            $history
        ));
    }
}
