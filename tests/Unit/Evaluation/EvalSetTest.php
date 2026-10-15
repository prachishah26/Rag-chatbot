<?php

namespace Tests\Unit\Evaluation;

use App\Services\Rag\Evaluation\EvalSet;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvalSetTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/evalset-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $contents): string
    {
        file_put_contents($path = $this->dir.'/'.$name, $contents);

        return $path;
    }

    public function test_loads_a_valid_set(): void
    {
        $path = $this->write('gpo.json', json_encode([
            'document' => 'GPO.pdf',
            'questions' => [
                ['question' => 'Revenue?', 'evidence' => ['874.5 million'], 'expect' => ['874.5']],
                ['question' => 'CEO?', 'out_of_scope' => true],
            ],
        ]));

        $set = EvalSet::fromFile($path);

        $this->assertSame('gpo', $set->name);
        $this->assertSame('GPO.pdf', $set->document);
        $this->assertCount(2, $set->questions);
        $this->assertTrue($set->questions[1]->outOfScope);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $path = $this->write('broken.json', '{"document": "x", "questions": [');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('broken.json is not valid JSON');

        EvalSet::fromFile($path);
    }

    public static function invalidSets(): array
    {
        return [
            'no document' => [['questions' => [['question' => 'Q', 'out_of_scope' => true]]], 'needs a "document"'],
            'no questions' => [['document' => 'x.pdf', 'questions' => []], 'non-empty "questions"'],
            'question without evidence' => [['document' => 'x.pdf', 'questions' => [['question' => 'Q', 'expect' => ['1']]]], 'question 1 needs "evidence"'],
            'question without expect' => [['document' => 'x.pdf', 'questions' => [
                ['question' => 'Q', 'out_of_scope' => true],
                ['question' => 'Q', 'evidence' => ['a']],
            ]], 'question 2 needs "evidence"'],
        ];
    }

    #[DataProvider('invalidSets')]
    public function test_invalid_set_is_rejected(array $data, string $message): void
    {
        $path = $this->write('set.json', json_encode($data));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        EvalSet::fromFile($path);
    }

    public function test_every_shipped_question_set_loads(): void
    {
        $files = glob(base_path('evals').'/*.json');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $set = EvalSet::fromFile($file);
            $this->assertNotEmpty($set->questions, basename($file));
            $this->assertNotSame('', $set->document, basename($file));
        }
    }
}
