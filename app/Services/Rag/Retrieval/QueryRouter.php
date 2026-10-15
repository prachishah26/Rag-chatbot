<?php

namespace App\Services\Rag\Retrieval;

use App\Services\Rag\Indexing\Heading;
use App\Services\Rag\Indexing\RegionCatalog;
use App\Services\Rag\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Query understanding: turns a question into a metadata filter, so "growth in South Asia" only
 * searches the South Asia sections. A wrong filter hides the answer, so a chapter filter is set
 * only when the question names a chapter, never guessed from its topic.
 */
class QueryRouter
{
    private const CHAPTER = '/\bchapter\s+(\d+|[IVXL]+)\b/i';

    public function __construct(
        private RegionCatalog $regions = new RegionCatalog(),
        private OpenAiCompatibleClient $client = new OpenAiCompatibleClient(),
    ) {}

    /** @return array<string, string> payload field => value, e.g. ["region" => "South Asia"] */
    public function route(string $question): array
    {
        return match ((string) config('rag.routing.driver')) {
            'none' => [],
            'llm' => $this->withLlm($question),
            default => $this->withRules($question),
        };
    }

    /** @return array<string, string> */
    public function withRules(string $question): array
    {
        return $this->compact([
            'region' => $this->regions->inQuestion($question),
            'chapter' => $this->namedChapter($question),
        ]);
    }

    /** One JSON call; any failure falls back to the rules. */
    public function withLlm(string $question): array
    {
        try {
            $answer = $this->askModel($question);
        } catch (Throwable $e) {
            Log::warning('LLM query routing failed; using the rules.', ['error' => $e->getMessage()]);

            return $this->withRules($question);
        }

        $region = in_array($answer['region'] ?? null, array_keys($this->regions->all()), true) ? $answer['region'] : null;

        // Never trust the model with a chapter the question does not name.
        return $this->compact(['region' => $region, 'chapter' => $this->namedChapter($question)]);
    }

    private function namedChapter(string $question): ?string
    {
        return preg_match(self::CHAPTER, $question, $m) ? Heading::chapterLabel($m[1]) : null;
    }

    private function askModel(string $question): array
    {
        $regionList = implode("\n", array_map(
            fn (string $region, array $aliases) => "- {$region} (includes: ".implode(', ', $aliases).')',
            array_keys($this->regions->all()),
            $this->regions->all()
        ));

        $prompt = <<<PROMPT
Route a question about a report to one of the report's regional sections.
Regions, with abbreviations and member countries:
{$regionList}

Return JSON: {"region": "<exact region name from the list>" or null}.
Set a region only when the question is about that region or a country in it. Use the report's
grouping above, not common usage (for example, Pakistan is in the Middle East, North Africa,
Afghanistan, and Pakistan region here). Otherwise use null.

Question: {$question}
PROMPT;

        $content = $this->client->complete([
            'model' => config('rag.openai_compat.model'),
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ], 'Query routing');

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Routing model did not return JSON.');
        }

        return $decoded;
    }

    private function compact(array $filter): array
    {
        return array_filter($filter, fn (?string $value) => $value !== null);
    }
}
