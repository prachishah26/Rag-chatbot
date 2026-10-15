<?php

namespace App\Services\Rag\Evaluation;

use InvalidArgumentException;

final readonly class EvalQuestion
{
    /**
     * @param  array<int, string>  $evidence  any of these phrases marks the answer passage
     * @param  array<int, string>  $expect  every one of these must appear in a correct answer
     */
    public function __construct(
        public string $question,
        public array $evidence,
        public array $expect,
        public bool $outOfScope,
    ) {}

    public static function fromArray(mixed $data, string $where): self
    {
        if (!is_array($data) || !is_string($data['question'] ?? null) || trim($data['question']) === '') {
            throw new InvalidArgumentException("{$where} needs a \"question\".");
        }

        $outOfScope = (bool) ($data['out_of_scope'] ?? false);
        $evidence = array_values(array_filter((array) ($data['evidence'] ?? []), 'is_string'));
        $expect = array_values(array_filter((array) ($data['expect'] ?? []), 'is_string'));

        if (!$outOfScope && (!$evidence || !$expect)) {
            throw new InvalidArgumentException("{$where} needs \"evidence\" and \"expect\" lists, or \"out_of_scope\": true.");
        }

        return new self(trim($data['question']), $evidence, $expect, $outOfScope);
    }
}
