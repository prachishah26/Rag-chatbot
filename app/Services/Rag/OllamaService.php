<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaService implements ChatModel
{
    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('rag.ollama.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('rag.ollama.timeout'));
    }

    public function embed(string $text): array
    {
        $response = $this->client()->post('/api/embed', [
            'model' => config('rag.ollama.embedding_model'),
            'input' => $text,
            // Fail loudly instead of silently embedding only part of an oversized chunk.
            'truncate' => false,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Ollama embedding failed: '.$response->body());
        }

        $embeddings = $response->json('embeddings');

        if (!is_array($embeddings) || !isset($embeddings[0]) || !is_array($embeddings[0])) {
            throw new RuntimeException('Ollama returned an invalid embedding response.');
        }

        return array_map('floatval', $embeddings[0]);
    }

    public function chat(string $question, string $context): string
    {
        return $this->complete([
            ['role' => 'system', 'content' => AnswerPrompt::INSTRUCTIONS],
            ['role' => 'user', 'content' => AnswerPrompt::userMessage($question, $context)],
        ]);
    }

    public function complete(array $messages): string
    {
        $response = $this->client()->post('/api/chat', [
            'model' => config('rag.ollama.chat_model'),
            'messages' => $messages,
            'options' => [
                'num_ctx' => (int) config('rag.ollama.num_ctx'),
                'temperature' => 0,
            ],
            'stream' => false,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Ollama chat failed: '.$response->body());
        }

        $content = data_get($response->json(), 'message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Ollama returned an empty answer.');
        }

        return trim($content);
    }
}
