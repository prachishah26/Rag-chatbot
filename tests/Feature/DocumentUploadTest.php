<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_a_text_document(): void
    {
        Storage::fake('local');
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent(
            'knowledge.txt',
            'Laravel is a PHP framework.'
        );

        $response = $this->post(route('documents.store'), [
            'document' => $file,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertDatabaseHas('documents', [
            'original_name' => 'knowledge.txt',
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessDocument::class);
    }
}
