<?php

namespace Tests\Feature;

use App\Services\Rag\RagService;
use App\Services\Rag\Retrieval\RetrievalResult;
use App\Services\Rag\Retrieval\Retriever;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class EvaluateRagCommandTest extends TestCase
{
    private string $dir;
    private MockInterface $retriever;
    private MockInterface $rag;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->dir = sys_get_temp_dir().'/rag-eval-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/small.json', json_encode([
            'document' => 'report.pdf',
            'questions' => [
                ['question' => 'What is SAR growth?', 'evidence' => ['soften to 6.3 percent'], 'expect' => ['6.3']],
                ['question' => 'What is EAP growth?', 'evidence' => ['EAP grows 4.4 percent'], 'expect' => ['4.4']],
                ['question' => 'Who is the CEO?', 'out_of_scope' => true],
            ],
        ]));
        config(['rag.eval_path' => $this->dir]);

        $this->retriever = Mockery::mock(Retriever::class);
        $this->retriever->shouldReceive('retrieve')->andReturnUsing(fn (string $question) => new RetrievalResult(
            match ($question) {
                // evidence first: rank 1
                'What is SAR growth?' => [$this->hit('Growth in SAR is expected to soften to 6.3 percent.')],
                // evidence second: rank 2
                'What is EAP growth?' => [$this->hit('noise'), $this->hit('EAP grows 4.4 percent.')],
                default => [],
            },
            [],
            ['region' => 'South Asia']
        ));
        $this->rag = Mockery::mock(RagService::class);

        $this->app->instance(Retriever::class, $this->retriever);
        $this->app->instance(RagService::class, $this->rag);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function hit(string $text): array
    {
        return ['score' => 0.5, 'payload' => ['document_name' => 'report.pdf', 'text' => $text]];
    }

    public function test_command_scores_mrr_and_answers(): void
    {
        $this->rag->shouldReceive('answer')->times(3)->andReturnUsing(fn (string $question) => ['answer' => match ($question) {
            'What is SAR growth?' => 'Growth is **6.3** percent.',
            'What is EAP growth?' => 'Growth is 4 percent.',
            default => RagService::NOT_FOUND,
        }]);

        $this->artisan('rag:eval', ['--pipeline' => ['advanced']])
            ->expectsOutputToContain('small (report.pdf)')
            ->expectsTable(['Document', 'Pipeline', 'MRR', 'Answers correct', 'Errors'], [
                // (1/1 + 1/2) / 2 in-scope questions = 0.75; 2 of 3 answers correct.
                ['report.pdf', 'advanced', '0.75', '2 / 3', 0],
            ])
            ->assertSuccessful();

        $reports = Storage::disk('local')->files('eval-reports');
        $this->assertCount(1, $reports);
        $report = json_decode(Storage::disk('local')->get($reports[0]), true);
        $this->assertSame(1, $report['details']['small']['advanced'][0]['rank']);
        $this->assertTrue($report['details']['small']['advanced'][2]['correct']);
    }

    public function test_retrieval_only_never_asks_for_answers(): void
    {
        $this->rag->shouldNotReceive('answer');

        $this->artisan('rag:eval', ['--pipeline' => ['advanced'], '--retrieval-only' => true])
            ->expectsTable(['Document', 'Pipeline', 'MRR', 'Answers correct', 'Errors'], [
                ['report.pdf', 'advanced', '0.75', '—', 0],
            ])
            ->assertSuccessful();
    }

    public function test_retrieval_error_is_counted_not_fatal(): void
    {
        $this->retriever = Mockery::mock(Retriever::class);
        $this->retriever->shouldReceive('retrieve')->andThrow(new \RuntimeException('Qdrant down'));
        $this->app->instance(Retriever::class, $this->retriever);
        $this->rag->shouldNotReceive('answer');

        $this->artisan('rag:eval', ['--pipeline' => ['naive'], '--retrieval-only' => true])
            ->expectsTable(['Document', 'Pipeline', 'MRR', 'Answers correct', 'Errors'], [
                ['report.pdf', 'naive', '0.00', '—', 3],
            ])
            ->assertSuccessful();
    }

    public function test_missing_question_sets_fail(): void
    {
        config(['rag.eval_path' => $this->dir.'/missing']);

        $this->artisan('rag:eval')->expectsOutputToContain('No question sets found')->assertFailed();
    }
}
