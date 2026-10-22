<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Chat through any OpenAI-compatible API (Groq, Cerebras, OpenRouter, Mistral, ...),
 * selected with RAG_CHAT_DRIVER=openai. Rate limits are per model, so when one is exhausted
 * the next of OPENAI_COMPAT_FALLBACK_MODELS is tried.
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
        $models = $this->models();

        foreach ($models as $index => $model) {
            try {
                return $this->client->complete([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0,
                ]);
            } catch (RateLimitException $e) {
                if ($index === count($models) - 1) {
                    throw $e;
                }

                Log::warning('Chat model rate limited; falling back.', [
                    'model' => $model,
                    'fallback' => $models[$index + 1],
                ]);
            }
        }

        throw new RuntimeException('OPENAI_COMPAT_MODEL is not set; add it to .env.');
    }

    /** @return array<int, string> the primary model, then each distinct fallback */
    private function models(): array
    {
        $models = [(string) config('rag.openai_compat.model'), ...(array) config('rag.openai_compat.fallback_models')];

        return array_values(array_unique(array_filter($models, fn ($model) => $model !== '')));
    }
}
