<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * POST /chat/completions on any OpenAI-compatible API (Groq by default). Free tiers cap tokens
 * per minute; on HTTP 429 this waits as long as the API asks (up to OPENAI_COMPAT_MAX_WAIT
 * seconds in total) and tries again.
 */
class OpenAiCompatibleClient
{
    // The wait budget is the real limit; this only stops a provider that keeps asking for 1 second.
    private const MAX_ATTEMPTS = 10;

    /**
     * @param  int|null  $maxWait  seconds to wait out rate limits; null uses OPENAI_COMPAT_MAX_WAIT
     * @return string the first choice's message content
     */
    public function complete(array $body, string $purpose = 'Chat API', ?int $maxWait = null): string
    {
        $response = $this->send($body, $purpose, $maxWait ?? (int) config('rag.openai_compat.max_wait'));
        $content = $response->json('choices.0.message.content');

        // Reasoning models may prefix their answer with a <think> block, sometimes left unclosed.
        $content = is_string($content) ? trim(preg_replace('/<think>.*?(<\/think>|$)/s', '', $content)) : '';

        if ($content === '') {
            throw new RuntimeException("{$purpose} returned an empty answer.");
        }

        return $content;
    }

    private function send(array $body, string $purpose, int $maxWait): Response
    {
        $apiKey = (string) config('rag.openai_compat.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_COMPAT_API_KEY is not set; add it to .env.');
        }

        $budget = max(0, $maxWait);

        for ($attempt = 1; ; $attempt++) {
            $response = Http::baseUrl(rtrim((string) config('rag.openai_compat.base_url'), '/'))
                ->withToken($apiKey)
                ->acceptJson()
                ->timeout((int) config('rag.openai_compat.timeout'))
                ->post('/chat/completions', $body);

            $wait = max(1, (int) ceil((float) $response->header('retry-after')));

            if ($response->status() !== 429 || $attempt >= self::MAX_ATTEMPTS || $wait > $budget) {
                break;
            }

            $budget -= $wait;
            $this->wait($wait);
        }

        if ($response->status() === 429) {
            // The provider says which limit was hit (per minute or per day) and when it resets.
            throw new RuntimeException(sprintf(
                '%s rate limit reached; wait a moment and try again. %s',
                $purpose,
                (string) ($response->json('error.message') ?? '')
            ));
        }

        if ($response->failed()) {
            // The body may echo request details; keep only the provider's error message.
            throw new RuntimeException(sprintf(
                '%s request failed (HTTP %d): %s',
                $purpose,
                $response->status(),
                (string) ($response->json('error.message') ?? 'unknown error')
            ));
        }

        return $response;
    }

    protected function wait(int $seconds): void
    {
        sleep($seconds);
    }
}
