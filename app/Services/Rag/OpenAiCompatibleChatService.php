<?php

namespace App\Services\Rag;

/**
 * Chat through any OpenAI-compatible API (Groq, Cerebras, OpenRouter, Mistral, ...),
 * selected with RAG_CHAT_DRIVER=openai.
 */
class OpenAiCompatibleChatService implements ChatModel
{
    public function __construct(private OpenAiCompatibleClient $client = new OpenAiCompatibleClient()) {}

    public function chat(string $question, string $context): string
    {
        return $this->complete([
            ['role' => 'system', 'content' => AnswerPrompt::INSTRUCTIONS],
            ['role' => 'user', 'content' => AnswerPrompt::userMessage($question, $context)],
        ]);
    }

    public function complete(array $messages): string
    {
        return $this->client->complete([
            'model' => config('rag.openai_compat.model'),
            'messages' => $messages,
            'temperature' => 0,
        ]);
    }
}
