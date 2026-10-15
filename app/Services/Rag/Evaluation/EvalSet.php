<?php

namespace App\Services\Rag\Evaluation;

use InvalidArgumentException;
use JsonException;

/**
 * A hand-checked question set for one document (evals/*.json). Each question names the
 * evidence that marks the answer passage (for MRR) and the facts a correct answer contains;
 * an out-of-scope question is answered correctly only by a refusal.
 */
final readonly class EvalSet
{
    /** @param  array<int, EvalQuestion>  $questions */
    public function __construct(
        public string $name,
        public string $document,
        public array $questions,
    ) {}

    public static function fromFile(string $path): self
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(basename($path).' is not valid JSON: '.$e->getMessage());
        }

        if (!is_string($data['document'] ?? null) || !is_array($data['questions'] ?? null) || !$data['questions']) {
            throw new InvalidArgumentException(basename($path).' needs a "document" name and a non-empty "questions" list.');
        }

        $questions = [];
        foreach ($data['questions'] as $i => $q) {
            $questions[] = EvalQuestion::fromArray($q, basename($path).' question '.($i + 1));
        }

        return new self(pathinfo($path, PATHINFO_FILENAME), $data['document'], $questions);
    }
}
