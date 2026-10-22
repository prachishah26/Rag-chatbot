<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\Rag\QdrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SharedDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_admin_can_upload_a_shared_document(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('documents.store'), ['document' => $this->file(), 'shared' => '1'])
            ->assertRedirect();

        $this->assertTrue(Document::sole()->isShared());
        Queue::assertPushed(ProcessDocument::class);
    }

    public function test_admin_upload_without_the_option_stays_private(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('documents.store'), ['document' => $this->file()])->assertRedirect();

        $this->assertSame($admin->id, Document::sole()->user_id);
    }

    public function test_non_admin_cannot_upload_a_shared_document(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('documents.store'), ['document' => $this->file(), 'shared' => '1'])
            ->assertForbidden();

        $this->assertDatabaseCount('documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_admin_can_delete_a_shared_document(): void
    {
        $document = Document::factory()->shared()->create();
        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('deleteByDocument')->once()->with($document->id);
        $this->app->instance(QdrantService::class, $qdrant);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertModelMissing($document);
    }

    public function test_admin_cannot_delete_another_users_private_document(): void
    {
        $document = Document::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('documents.destroy', $document))
            ->assertNotFound();

        $this->assertModelExists($document);
    }

    public function test_share_option_is_shown_to_admins_only(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('documents.index'))->assertSee('Share with all users');
        $this->actingAs(User::factory()->create())->get(route('documents.index'))->assertDontSee('Share with all users');
    }

    public function test_registration_cannot_make_an_admin(): void
    {
        $this->post(route('register'), [
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'is_admin' => '1',
        ]);

        $this->assertFalse(User::where('email', 'eve@example.com')->sole()->is_admin);
    }

    public function test_command_grants_and_revokes_admin(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('user:admin', ['email' => 'ada@example.com'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);

        $this->artisan('user:admin', ['email' => 'ada@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_command_fails_for_an_unknown_email(): void
    {
        $this->artisan('user:admin', ['email' => 'nobody@example.com'])->assertFailed();
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('handbook.txt', 'Shared handbook text.');
    }
}
