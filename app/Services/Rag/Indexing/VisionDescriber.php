<?php

namespace App\Services\Rag\Indexing;

use App\Services\Rag\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * One vision-model call per visual page (Groq's Qwen 3.8 by default), cached by the rendered
 * image, so re-indexing a document costs nothing. Vision needs time on free API tiers, so it
 * waits out rate limits for up to a minute per page. A failed page is logged and skipped: the
 * page's extracted text is still indexed.
 */
class VisionDescriber
{
    public const NO_VISUAL = 'NONE';

    private const PROMPT = <<<'PROMPT'
You are indexing a page of a PDF report for search. Describe every chart, diagram, infographic and
call-out on this page so a reader who cannot see it gets the same facts:
- Give each chart's title, what it measures and its units.
- List every labelled value with its label, exactly as printed.
- State the relationships the visual shows, such as totals, differences and which bar is largest,
  e.g. "445 kg baseline minus 99 kg of reductions gives 346 kg".
- Keep footnote numbers next to the values they mark, written like [^7].
Write plain sentences, no preamble. If the page has only ordinary text and no visual, reply exactly: NONE
PROMPT;

    private const CACHE_DIR = 'vision-cache';
    // Indexing runs in the background, so a page may wait out a full rate-limit minute.
    private const MAX_WAIT_SECONDS = 65;

    public function __construct(private OpenAiCompatibleClient $client = new OpenAiCompatibleClient()) {}

    /**
     * Pages are described until the time budget (RAG_VISION_BUDGET_SECONDS) runs out; the rest are
     * skipped and logged. Cached pages cost nothing, so a later re-index continues where this stopped.
     *
     * @return array<int, string> descriptions keyed by page; pages without a visual are left out
     */
    public function describe(string $pdfPath, array $pages): array
    {
        $descriptions = [];
        $deadline = $this->now() + max(0, (int) config('rag.vision.budget_seconds'));
        $skipped = [];

        foreach ($pages as $page) {
            $remaining = $deadline - $this->now();

            try {
                $png = $this->render($pdfPath, $page);
                $cached = $this->cached($png);

                if ($cached === null && $remaining <= 0) {
                    $skipped[] = $page;
                    continue;
                }

                $text = $cached ?? $this->fresh($png, min(self::MAX_WAIT_SECONDS, $remaining));
            } catch (Throwable $e) {
                Log::warning('Vision description failed; the page keeps its extracted text only.', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($text !== '' && !$this->saysNoVisual($text)) {
                $descriptions[$page] = "Visual content on page {$page}: ".$text;
            }
        }

        if ($skipped) {
            Log::warning('Vision time budget used up; these pages keep their extracted text only. Re-index to continue.', [
                'pages' => $skipped,
            ]);
        }

        return $descriptions;
    }

    private function cached(string $png): ?string
    {
        $file = $this->cacheFile($png);

        return Storage::disk('local')->exists($file) ? (string) Storage::disk('local')->get($file) : null;
    }

    private function fresh(string $png, int $maxWait): string
    {
        $text = $this->ask($png, (string) config('rag.vision.model'), $maxWait);
        Storage::disk('local')->put($this->cacheFile($png), $text);

        return $text;
    }

    private function cacheFile(string $png): string
    {
        return self::CACHE_DIR.'/'.hash('sha256', config('rag.vision.model')."\n".self::PROMPT."\n".$png).'.txt';
    }

    private function render(string $pdfPath, int $page): string
    {
        $result = Process::timeout(60)->run([
            config('rag.pdftoppm_binary'), '-f', (string) $page, '-l', (string) $page,
            '-r', (string) config('rag.vision.dpi'), '-png', '-singlefile', $pdfPath,
        ]);

        if ($result->failed() || $result->output() === '') {
            throw new RuntimeException('pdftoppm could not render the page: '.$result->errorOutput());
        }

        return $result->output();
    }

    private function ask(string $png, string $model, int $maxWait): string
    {
        return $this->client->complete([
            'model' => $model,
            'temperature' => 0,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::PROMPT],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.base64_encode($png)]],
                ],
            ]],
        ], 'Vision', max(0, $maxWait));
    }

    protected function now(): int
    {
        return time();
    }

    /** "NONE", or an explanation that ends with it: the page has nothing to describe. */
    private function saysNoVisual(string $text): bool
    {
        $lines = preg_split('/\R/', trim($text));

        return strtoupper(trim((string) end($lines), " .*")) === self::NO_VISUAL;
    }

}
