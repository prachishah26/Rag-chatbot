<?php

namespace App\Console\Commands;

use App\Services\Rag\Evaluation\EvalSet;
use App\Services\Rag\Evaluation\Scorer;
use App\Services\Rag\RagService;
use App\Services\Rag\Retrieval\Retriever;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Scores the naive and advanced pipelines on hand-checked questions:
 * mean reciprocal rank (MRR) of the answer passage, and how many answers are correct.
 * With 10-14 questions per document, treat differences under about 0.1 as noise.
 */
class EvaluateRag extends Command
{
    protected $signature = 'rag:eval
        {--set=* : Question set file names in evals/ (default: all)}
        {--pipeline=* : naive, advanced (default: both)}
        {--retrieval-only : Score MRR only; no answers, no LLM calls}';

    protected $description = 'Measure retrieval (MRR) and answer correctness on the hand-checked question sets';

    public function handle(RagService $rag, Retriever $retriever, Scorer $scorer): int
    {
        $sets = $this->sets();
        $pipelines = $this->option('pipeline') ?: [Retriever::NAIVE, Retriever::ADVANCED];

        if (!$sets) {
            $this->error('No question sets found in '.config('rag.eval_path').'.');

            return self::FAILURE;
        }

        // Answering every question hits free-tier rate limits; wait them out instead of failing.
        config(['rag.openai_compat.max_wait' => 300]);

        $summary = [];
        $details = [];

        foreach ($sets as $set) {
            $this->newLine();
            $this->info("{$set->name} ({$set->document}) — ".count($set->questions).' questions');

            foreach ($pipelines as $pipeline) {
                $rows = array_map(
                    fn ($question) => $this->evaluate($question, $set, $pipeline, $rag, $retriever, $scorer),
                    $set->questions
                );
                $details[$set->name][$pipeline] = $rows;
                $summary[] = $this->summarize($set, $pipeline, $rows);
            }
        }

        $this->newLine();
        $this->table(['Document', 'Pipeline', 'MRR', 'Answers correct', 'Errors'], $summary);
        $this->line('MRR: 1.0 = the answer passage always ranked first; 0.5 = second on average. Out-of-scope questions count for answers only.');

        $report = 'eval-reports/'.now()->format('Y-m-d_His').'.json';
        Storage::disk('local')->put($report, json_encode(['summary' => $summary, 'details' => $details], JSON_PRETTY_PRINT));
        $this->line('Full report: '.Storage::disk('local')->path($report));

        return self::SUCCESS;
    }

    private function evaluate($question, EvalSet $set, string $pipeline, RagService $rag, Retriever $retriever, Scorer $scorer): array
    {
        $row = ['question' => $question->question, 'rank' => null, 'answer' => null, 'correct' => null, 'error' => null];

        try {
            $retrieval = $retriever->retrieve($question->question, $pipeline);
            $row['rank'] = $question->outOfScope ? null : $scorer->rank($retrieval->hits, $set->document, $question);
            $row['filter'] = $retrieval->filter;

            if (!$this->option('retrieval-only')) {
                $row['answer'] = $rag->answer($question->question, $retrieval)['answer'];
                $row['correct'] = $scorer->isCorrect($row['answer'], $question);
            }
        } catch (Throwable $e) {
            report($e);
            $row['error'] = $e->getMessage();
        }

        $this->line(sprintf(
            '  [%s] rank %-4s %-6s %s',
            $pipeline,
            $question->outOfScope ? 'n/a' : ($row['rank'] ?? '—'),
            $row['error'] ? 'ERROR' : ($row['correct'] === null ? '' : ($row['correct'] ? 'ok' : 'wrong')),
            mb_strimwidth($question->question, 0, 70, '…')
        ));

        return $row;
    }

    private function summarize(EvalSet $set, string $pipeline, array $rows): array
    {
        $scorer = new Scorer();
        $inScope = array_filter($rows, fn (array $row, int $i) => !$set->questions[$i]->outOfScope, ARRAY_FILTER_USE_BOTH);
        $mrr = $inScope ? array_sum(array_map(fn (array $row) => $scorer->reciprocalRank($row['rank']), $inScope)) / count($inScope) : 0;
        $answered = array_filter($rows, fn (array $row) => $row['correct'] !== null);

        return [
            $set->document,
            $pipeline,
            number_format($mrr, 2),
            $this->option('retrieval-only') ? '—' : count(array_filter($answered, fn (array $row) => $row['correct'])).' / '.count($rows),
            count(array_filter($rows, fn (array $row) => $row['error'] !== null)),
        ];
    }

    /** @return array<int, EvalSet> */
    private function sets(): array
    {
        $files = glob(rtrim((string) config('rag.eval_path'), '/').'/*.json') ?: [];
        $wanted = $this->option('set');

        if ($wanted) {
            $files = array_filter($files, fn (string $file) => in_array(pathinfo($file, PATHINFO_FILENAME), $wanted, true)
                || in_array(basename($file), $wanted, true));
        }

        return array_values(array_map(fn (string $file) => EvalSet::fromFile($file), $files));
    }
}
