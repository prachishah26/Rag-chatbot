<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $name = fake()->unique()->slug(2).'.pdf';

        return [
            'user_id' => User::factory(),
            'original_name' => $name,
            'path' => "documents/{$name}",
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'status' => Document::COMPLETED,
            'chunk_count' => 5,
            'processed_at' => now(),
        ];
    }

    /** A document without an owner: indexed from the CLI, or uploaded before documents had owners. */
    public function shared(): static
    {
        return $this->state(['user_id' => null]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
