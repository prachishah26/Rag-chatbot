<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Rag\QdrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DocumentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_document_belongs_to_the_uploader(): void
    {
        Storage::fake('local');
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('documents.store'), [
            'document' => UploadedFile::fake()->createWithContent('mine.txt', 'Some text.'),
        ])->assertRedirect();

        $this->assertSame($user->id, Document::sole()->user_id);
    }

    public function test_list_shows_own_and_shared_documents_but_not_other_users(): void
    {
        $user = User::factory()->create();
        Document::factory()->for($user)->create(['original_name' => 'mine.pdf', 'chunk_count' => 4]);
        Document::factory()->shared()->create(['original_name' => 'shared.pdf', 'chunk_count' => 6]);
        Document::factory()->create(['original_name' => 'theirs.pdf', 'chunk_count' => 100]);

        $this->actingAs($user)->get(route('documents.index'))
            ->assertOk()
            ->assertSee(['mine.pdf', 'shared.pdf'])
            ->assertDontSee('theirs.pdf')
            ->assertViewHas('stats', fn ($stats) => (int) $stats->total === 2 && (int) $stats->chunks === 10);
    }

    public function test_user_cannot_delete_another_users_document(): void
    {
        $document = Document::factory()->create();
        $this->expectNoVectorDeletes();

        $this->actingAs(User::factory()->create())
            ->delete(route('documents.destroy', $document))
            ->assertNotFound();

        $this->assertModelExists($document);
    }

    public function test_shared_document_cannot_be_deleted_from_the_web(): void
    {
        $document = Document::factory()->shared()->create();
        $this->expectNoVectorDeletes();

        $this->actingAs(User::factory()->create())
            ->delete(route('documents.destroy', $document))
            ->assertForbidden();

        $this->assertModelExists($document);
    }

    private function expectNoVectorDeletes(): void
    {
        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldNotReceive('deleteByDocument');
        $this->app->instance(QdrantService::class, $qdrant);
    }
}
