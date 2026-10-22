<?php

namespace App\Services\Rag;

use App\Services\Rag\Indexing\Element;
use App\Services\Rag\Retrieval\RetrievalResult;
use App\Services\Rag\Retrieval\Retriever;

class RagService
{
    public const NOT_FOUND = 'I could not find this in the document.';

    public function __construct(
        protected Retriever $retriever,
        // Writes the answer; Ollama or an OpenAI-compatible API (see RAG_CHAT_DRIVER).
        protected ChatModel $chat,
        protected QuoteExtractor $quoteExtractor = new QuoteExtractor(),
        protected TableParser $tables = new TableParser(),
    ) {}

    /** @param  int[]|null  $documentIds  the documents to search; null searches the whole index */
    public function ask(string $question, string $pipeline = Retriever::ADVANCED, ?array $documentIds = null): array
    {
        return $this->answer($question, $this->retriever->retrieve($question, $pipeline, $documentIds));
    }

    /** Writes a grounded, cited answer from passages already retrieved. */
    public function answer(string $question, RetrievalResult $retrieval): array
    {
        $maxChars = (int) config('rag.retrieval.max_context_chars');
        $usedChars = 0;
        $contextParts = [];
        $sources = [];
        $chunks = [];

        foreach ($retrieval->passages as $passage) {
            if ($passage->text === '') {
                continue;
            }

            $part = sprintf(
                "[Source %d | %s%s%s]\n%s",
                count($contextParts) + 1,
                $passage->document,
                $passage->headingPath !== '' ? ' | '.$passage->headingPath : '',
                $passage->page !== null ? ' | page '.$passage->page : '',
                // Each table value named by its column: models misalign wide Markdown tables.
                $passage->type === Element::TABLE ? $this->tables->labelledRows($passage->text) : $passage->text
            );

            // Passages are ranked best-first; stop before overflowing the model's context.
            $separatorChars = $contextParts ? 2 : 0;
            if ($maxChars > 0 && $usedChars + $separatorChars + strlen($part) > $maxChars) {
                break;
            }

            $usedChars += $separatorChars + strlen($part);
            $contextParts[] = $part;
            $chunks[] = ['document' => $passage->document, 'chunk' => $passage->chunk, 'text' => $passage->text];

            $sources[] = [
                'document' => $passage->document,
                'chunk' => $passage->chunk,
                'section' => $passage->headingPath,
                'page' => $passage->page,
                'score' => round($passage->score, 4),
            ];
        }

        $notFound = ['answer' => self::NOT_FOUND, 'quotes' => [], 'sources' => $sources, 'filter' => $retrieval->filter];

        if (!$contextParts) {
            return $notFound;
        }

        $modelAnswer = $this->chat->chat($question, implode("\n\n", $contextParts));

        // The prompt asks for the exact refusal; an answer that merely uses the words is kept.
        if (str_starts_with(strtolower(trim($this->withoutSourceTags($modelAnswer), " \"*_")), strtolower(rtrim(self::NOT_FOUND, '.')))) {
            return $notFound;
        }

        // A model sometimes invents or misreads a figure; never show one the sources lack.
        if (!$this->figuresAreGrounded($this->withoutSourceTags($modelAnswer), $chunks, $question)) {
            return $notFound;
        }

        $cited = $this->citedSourceNumbers($modelAnswer);

        return [
            'answer' => $this->withoutSourceTags($modelAnswer),
            // Document sentences backing the generated answer, so users can verify it.
            'quotes' => $this->evidence($this->withoutSourceTags($modelAnswer), $chunks),
            'sources' => array_map(
                fn (array $source, int $i) => [...$source, 'cited' => in_array($i + 1, $cited, true)],
                $sources,
                array_keys($sources)
            ),
            'filter' => $retrieval->filter,
        ];
    }

    /**
     * Every number in the answer (ignoring thousands separators) must appear in the sources, or in
     * the question itself so a correction can repeat it ("$874.5 million, not $900 million").
     */
    private function figuresAreGrounded(string $answer, array $chunks, string $question): bool
    {
        $available = array_flip($this->figures(implode(' ', [...array_column($chunks, 'text'), $question])));

        $grounded = [];
        $derived = [];

        foreach ($this->figures($answer) as $figure) {
            if (isset($available[$figure]) || $this->isRescaledFigure($figure, array_keys($available))) {
                $grounded[] = $figure;
            } else {
                $derived[] = $figure;
            }
        }

        foreach ($derived as $figure) {
            if (!$this->isSumOrDifference($figure, $grounded)) {
                return false;
            }
        }

        return true;
    }

    /**
     * "$12.1 million" is grounded when the same answer states "$91.6 million" and "$79.5 million":
     * a calculated figure must be the sum or difference of two grounded figures in that answer.
     */
    private function isSumOrDifference(string $figure, array $grounded): bool
    {
        $dot = strpos($figure, '.');
        $decimals = $dot === false ? 0 : strlen($figure) - $dot - 1;
        $count = count($grounded);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = (float) $grounded[$i];
                $b = (float) $grounded[$j];

                foreach ([$a + $b, abs($a - $b)] as $result) {
                    if (number_format($result, $decimals, '.', '') === $figure) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * "1.018" (billion) is grounded by "1,018,063" (thousand): a decimal figure may be a source
     * figure divided by a thousand, million or billion and rounded to the same precision.
     */
    private function isRescaledFigure(string $figure, array $sourceFigures): bool
    {
        $dot = strpos($figure, '.');

        if ($dot === false) {
            return false;
        }

        $decimals = strlen($figure) - $dot - 1;

        foreach ($sourceFigures as $source) {
            foreach ([1e3, 1e6, 1e9] as $scale) {
                if (number_format((float) $source / $scale, $decimals, '.', '') === $figure) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int, string> numbers such as "1,737" or "52.6", normalised to "1737" and "52.6" */
    private function figures(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)*/', $text, $m);

        return array_values(array_unique(array_map(fn (string $n) => str_replace(',', '', $n), $m[0])));
    }

    /** Exact document sentences that support each sentence of the answer. */
    private function evidence(string $answer, array $chunks): array
    {
        $quotes = [];
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"“$(])/u', $answer, -1, PREG_SPLIT_NO_EMPTY) ?: [$answer];

        foreach ($sentences as $sentence) {
            foreach ($this->quoteExtractor->ground($sentence, $chunks) as $quote) {
                $quotes[$quote['text']] ??= $quote;
            }
        }

        return array_values($quotes);
    }

    /** @return array<int, int> 1-based source numbers the model cited, e.g. "[Source 1, 3]". */
    private function citedSourceNumbers(string $answer): array
    {
        preg_match_all('/[\[【]Sources?\s+([^\]】]+)[\]】]/iu', $answer, $tags);
        preg_match_all('/\d+/', implode(' ', $tags[1]), $numbers);

        return array_values(array_unique(array_map('intval', $numbers[0])));
    }

    private function withoutSourceTags(string $answer): string
    {
        // Some models (e.g. gpt-oss) cite with full-width brackets: 【Source 2】.
        // Footnote markers ("[^7]") copied from the sources are noise in the answer.
        return trim(preg_replace(
            ['/\h*[\[【]Sources?\s+[^\]】]+[\]】]/iu', '/\[\^\d+\]/', '/\h+([.,;:])/u'],
            ['', '', '$1'],
            $answer
        ));
    }
}
