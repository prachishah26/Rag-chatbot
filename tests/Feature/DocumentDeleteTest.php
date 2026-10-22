<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\Rag\QdrantService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DocumentDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_deleting_a_document_also_removes_its_vectors(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/report.pdf', 'pdf');

        $document = Document::create([
            'original_name' => 'report.pdf',
            'path' => 'documents/report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 3,
            'status' => 'completed',
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('deleteByDocument')->once()->with($document->id);
        $this->app->instance(QdrantService::class, $qdrant);

        $this->delete(route('documents.destroy', $document))->assertRedirect();

        $this->assertModelMissing($document);
        Storage::disk('local')->assertMissing('documents/report.pdf');
    }

    public function test_document_is_kept_when_vector_removal_fails(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/report.pdf', 'pdf');

        $document = Document::create([
            'original_name' => 'report.pdf',
            'path' => 'documents/report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 3,
            'status' => 'completed',
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('deleteByDocument')->andThrow(new \RuntimeException('Qdrant down'));
        $this->app->instance(QdrantService::class, $qdrant);

        $this->delete(route('documents.destroy', $document))->assertRedirect()->assertSessionHas('error');

        $this->assertModelExists($document);
        Storage::disk('local')->assertExists('documents/report.pdf');
    }

    public function test_document_cannot_be_deleted_while_it_is_being_indexed(): void
    {
        $document = Document::create([
            'original_name' => 'report.pdf',
            'path' => 'documents/report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 3,
            'status' => 'processing',
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldNotReceive('deleteByDocument');
        $this->app->instance(QdrantService::class, $qdrant);

        $this->delete(route('documents.destroy', $document))->assertRedirect()->assertSessionHas('error');

        $this->assertModelExists($document);
    }
}
