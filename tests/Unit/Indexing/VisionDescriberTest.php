<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\VisionDescriber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisionDescriberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.openai_compat.api_key' => 'test-key',
            'rag.openai_compat.base_url' => 'https://vision.test/v1',
            'rag.openai_compat.max_wait' => 0,
            'rag.vision.model' => 'test-vision-model',
            'rag.pdftoppm_binary' => 'pdftoppm',
        ]);
        Storage::fake('local');
        Process::fake(['*' => Process::result(output: 'fake-png-bytes')]);
    }

    private function reply(string $content): void
    {
        Http::fake(['vision.test/*' => Http::response(['choices' => [['message' => ['content' => $content]]]])]);
    }

    public function test_a_description_is_prefixed_with_its_page(): void
    {
        $this->reply('A bar chart shows 445 kg baseline.');

        $descriptions = (new VisionDescriber())->describe('/docs/report.pdf', [3]);

        $this->assertSame([3 => 'Visual content on page 3: A bar chart shows 445 kg baseline.'], $descriptions);
        Process::assertRan(fn ($process) => in_array('/docs/report.pdf', $process->command, true)
            && in_array('-png', $process->command, true));
        // Process fakes end output with a newline; the image is sent as a base64 PNG data URL.
        Http::assertSent(fn ($request) => $request->url() === 'https://vision.test/v1/chat/completions'
            && $request['model'] === 'test-vision-model'
            && str_starts_with($request['messages'][0]['content'][1]['image_url']['url'], 'data:image/png;base64,'.rtrim(base64_encode('fake-png-bytes'), '=')));
    }

    public function test_a_description_is_cached_so_a_second_call_makes_no_request(): void
    {
        $this->reply('A pie chart.');

        $first = (new VisionDescriber())->describe('/docs/report.pdf', [2]);
        $second = (new VisionDescriber())->describe('/docs/report.pdf', [2]);

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        $this->assertCount(1, Storage::disk('local')->files('vision-cache'));
    }

    public function test_a_none_reply_is_skipped(): void
    {
        $this->reply('NONE');

        $this->assertSame([], (new VisionDescriber())->describe('/docs/report.pdf', [1]));
    }

    public function test_an_explanation_ending_in_none_is_skipped(): void
    {
        $this->reply("The page only has paragraphs of text.\n**NONE.**");

        $this->assertSame([], (new VisionDescriber())->describe('/docs/report.pdf', [1]));
    }

    public function test_an_http_failure_is_logged_and_skipped(): void
    {
        Http::fake(['vision.test/*' => Http::response(['error' => ['message' => 'server down']], 500)]);
        Log::spy();

        $this->assertSame([], (new VisionDescriber())->describe('/docs/report.pdf', [4]));

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['page'] === 4 && str_contains($context['error'], 'HTTP 500')
        );
        $this->assertSame([], Storage::disk('local')->files('vision-cache'));
    }

    public function test_a_failed_render_is_logged_and_skipped_without_calling_the_api(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'no such file', exitCode: 1)]);
        Http::fake();
        Log::spy();

        $this->assertSame([], (new VisionDescriber())->describe('/docs/missing.pdf', [1]));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_one_failed_page_does_not_stop_the_others(): void
    {
        Http::fakeSequence('vision.test/*')
            ->push(['error' => ['message' => 'bad']], 500)
            ->push(['choices' => [['message' => ['content' => 'A map.']]]]);
        Process::fake(['*' => Process::sequence()
            ->push(Process::result(output: 'png-page-1'))
            ->push(Process::result(output: 'png-page-2'))]);
        Log::spy();

        $this->assertSame([2 => 'Visual content on page 2: A map.'], (new VisionDescriber())->describe('/docs/report.pdf', [1, 2]));
    }

    public function test_pages_past_the_time_budget_are_skipped_unless_cached(): void
    {
        config(['rag.vision.budget_seconds' => 0]);
        \Illuminate\Support\Facades\Http::fake();

        $this->assertSame([], (new \App\Services\Rag\Indexing\VisionDescriber())->describe('/tmp/doc.pdf', [1, 2]));
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_a_cached_page_is_used_even_when_the_budget_is_spent(): void
    {
        config(['rag.vision.budget_seconds' => 60]);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'choices' => [['message' => ['content' => 'A bar chart: 445 kg minus 99 kg gives 346 kg.']]],
        ])]);
        (new \App\Services\Rag\Indexing\VisionDescriber())->describe('/tmp/doc.pdf', [2]);

        config(['rag.vision.budget_seconds' => 0]);
        $descriptions = (new \App\Services\Rag\Indexing\VisionDescriber())->describe('/tmp/doc.pdf', [2]);

        $this->assertStringContainsString('346 kg', $descriptions[2]);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }
}
