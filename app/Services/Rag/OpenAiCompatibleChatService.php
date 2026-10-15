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
        return $this->client->complete([
            'model' => config('rag.openai_compat.model'),
            'messages' => [
                ['role' => 'system', 'content' => AnswerPrompt::INSTRUCTIONS],
                ['role' => 'user', 'content' => AnswerPrompt::userMessage($question, $context)],
            ],
            'temperature' => 0,
        ]);
    }
}
