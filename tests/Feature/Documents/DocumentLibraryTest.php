<?php

namespace Tests\Feature\Documents;

use App\Models\Plan;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFolder;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * The document library.
 *
 * The parts worth pinning are the ones that would be expensive to discover on a
 * customer's files: a document one workspace can reach from another, a limit
 * that does not limit, and a delete that either loses the file for good or never
 * frees the space it promised.
 */
class DocumentLibraryTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateDisk();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function upload(string $name = 'contract.pdf', int $kb = 8, array $extra = []): TestResponse
    {
        return $this->actingAs($this->ctx['user'])->post(route('client.documents.store'), array_merge([
            'files' => [UploadedFile::fake()->create($name, $kb, 'application/pdf')],
        ], $extra));
    }

    private function document(?int $workspaceId = null, array $attrs = []): Document
    {
        return Document::create(array_merge([
            'workspace_id' => $workspaceId ?? $this->ctx['workspace']->id,
            'name' => 'contract.pdf',
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
        ], $attrs));
    }

    /** A plan whose whole allowance is $mb megabytes. */
    private function planWithStorage(int $mb): void
    {
        $plan = Plan::factory()->create(['limits' => ['users' => 5, 'storage' => $mb]]);
        $this->attachPlanToClient($this->ctx['client'], $plan);
    }

    // ─── the basics ──────────────────────────────────────────────────────

    public function test_an_uploaded_file_lands_on_the_private_disk_and_in_the_list(): void
    {
        $this->upload()->assertRedirect();

        $document = Document::where('workspace_id', $this->ctx['workspace']->id)->first();

        $this->assertSame('contract.pdf', $document->name);
        $this->assertSame('pdf', $document->extension);
        $this->assertSame('upload', $document->source);
        $this->assertSame($this->ctx['user']->id, $document->created_by);
        // Private disk, and a stored name that is a uuid rather than the one it
        // arrived under.
        $this->assertTrue($this->privateDisk()->exists($document->path));
        $this->assertNotOnTheWebServersDisk($document->path, 'An uploaded document');
        $this->assertStringNotContainsString('contract', $document->path);
    }

    public function test_the_stored_name_never_comes_from_the_uploader(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.documents.store'), [
            'files' => [UploadedFile::fake()->createWithContent('factura.pdf', '%PDF-1.4 x')],
        ]);

        $document = Document::first();

        // Whatever it was called, it is filed by what it is.
        $this->assertStringEndsWith('.pdf', $document->path);
        $this->assertStringNotContainsString('.php', $document->path);
    }

    public function test_a_type_that_a_browser_would_execute_is_refused(): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.documents.store'), [
                'files' => [UploadedFile::fake()->createWithContent('pagina.html', '<script>alert(1)</script>')],
            ])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, Document::count());
    }

    public function test_the_list_shows_only_this_workspaces_documents(): void
    {
        $mine = $this->document();
        $intruder = $this->createWorkspaceContext();
        $this->document($intruder['workspace']->id);

        $props = $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.index'))
            ->viewData('page')['props'];

        $this->assertCount(1, $props['documents']['data']);
        $this->assertSame($mine->uuid, $props['documents']['data'][0]['uuid']);
    }

    public function test_the_list_does_not_hand_the_browser_a_storage_path(): void
    {
        $this->upload();

        $props = $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.index'))
            ->viewData('page')['props'];

        // The screen addresses a document by uuid, through a route that resolves
        // the workspace before it reads a byte. It has never needed the path.
        //
        // A path is not a secret the way a password is, which is exactly what
        // makes it easy to wave through — and it stops being harmless the moment
        // the private disk gains any way to be addressed: a 'url' key on the
        // disk, a bucket flipped to public, a signed storage.local link minted
        // somewhere else in the app. Every path already sitting in a page source,
        // a screenshot or a support ticket becomes a live link at that point,
        // retroactively, for every file the product has ever listed.
        $this->assertArrayNotHasKey('path', $props['documents']['data'][0]);
        $this->assertArrayNotHasKey('disk', $props['documents']['data'][0]);
    }

    // ─── who may reach a file ────────────────────────────────────────────

    public function test_the_owner_can_download_one(): void
    {
        $this->upload();
        $document = Document::first();

        $response = $this->actingAs($this->ctx['user'])->get(route('client.documents.file', $document->uuid));

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    public function test_a_safe_type_can_be_shown_in_the_page_with_a_sandbox(): void
    {
        $this->upload();
        $document = Document::first();

        $response = $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.file', ['document' => $document->uuid, 'preview' => 1]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('content-security-policy'));
    }

    public function test_a_type_outside_the_list_cannot_be_shown_in_the_page(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.documents.store'), [
            'files' => [UploadedFile::fake()->create('arhiva.zip', 4, 'application/zip')],
        ]);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.file', ['document' => Document::first()->uuid, 'preview' => 1]))
            ->assertNotFound();
    }

    public function test_another_workspace_cannot_read_rename_or_delete_one(): void
    {
        $document = $this->document();
        $intruder = $this->createWorkspaceContext();

        $this->actingAs($intruder['user'])->get(route('client.documents.file', $document->uuid))->assertForbidden();
        $this->actingAs($intruder['user'])->patch(route('client.documents.update', $document->uuid), ['name' => 'al meu.pdf'])->assertForbidden();
        $this->actingAs($intruder['user'])->delete(route('client.documents.destroy', $document->uuid))->assertForbidden();

        $this->assertSame('contract.pdf', $document->fresh()->name);
    }

    // ─── folders and clients, the two separate axes ──────────────────────

    public function test_a_document_can_be_filed_and_attributed_to_a_client(): void
    {
        $document = $this->document();
        $folder = DocumentFolder::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'Contracte']);
        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);

        $this->actingAs($this->ctx['user'])->patch(route('client.documents.update', $document->uuid), [
            'folder_id' => $folder->id,
            'contact_id' => $contact->id,
        ])->assertRedirect();

        $document->refresh();
        $this->assertSame($folder->id, $document->folder_id);
        $this->assertSame($contact->id, $document->contact_id);
    }

    public function test_a_folder_or_contact_from_another_workspace_is_simply_not_applied(): void
    {
        $document = $this->document();
        $intruder = $this->createWorkspaceContext();
        $theirFolder = DocumentFolder::create(['workspace_id' => $intruder['workspace']->id, 'name' => 'Al lor']);
        $theirContact = Contact::factory()->create(['workspace_id' => $intruder['workspace']->id]);

        $this->actingAs($this->ctx['user'])->patch(route('client.documents.update', $document->uuid), [
            'folder_id' => $theirFolder->id,
            'contact_id' => $theirContact->id,
        ]);

        // Not an error and not applied: the ownership is a condition of the
        // lookup, so a foreign id resolves to nothing.
        $document->refresh();
        $this->assertNull($document->folder_id);
        $this->assertNull($document->contact_id);
    }

    public function test_two_folders_cannot_share_a_name_in_the_same_place(): void
    {
        DocumentFolder::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'Facturi']);

        $this->actingAs($this->ctx['user'])
            ->post(route('client.documents.folders.store'), ['name' => 'Facturi'])
            ->assertSessionHasErrors('name');
    }

    public function test_deleting_a_folder_keeps_the_documents_in_it(): void
    {
        $folder = DocumentFolder::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'Vechi']);
        $document = $this->document(attrs: ['folder_id' => $folder->id]);

        $this->actingAs($this->ctx['user'])
            ->delete(route('client.documents.folders.destroy', $folder->id))
            ->assertRedirect();

        // Tidying the shelves is not throwing away the contracts.
        $this->assertNull($document->fresh()->folder_id);
        $this->assertNotNull($document->fresh());
    }

    // ─── the allowance ───────────────────────────────────────────────────

    public function test_the_plans_storage_value_is_what_limits(): void
    {
        $this->planWithStorage(1); // one megabyte

        $props = $this->actingAs($this->ctx['user'])->get(route('client.documents.index'))->viewData('page')['props'];

        $this->assertSame(1024 * 1024, $props['storage']['limit']);
    }

    public function test_an_upload_that_does_not_fit_is_refused(): void
    {
        $this->planWithStorage(1);

        $this->actingAs($this->ctx['user'])->post(route('client.documents.store'), [
            'files' => [UploadedFile::fake()->create('mare.pdf', 2048, 'application/pdf')],
        ])->assertSessionHas('error');

        $this->assertSame(0, Document::count());
    }

    public function test_the_allowance_counts_email_attachments_as_well(): void
    {
        $this->planWithStorage(1);
        $this->document(attrs: ['size_bytes' => 600 * 1024]);

        $props = $this->actingAs($this->ctx['user'])->get(route('client.documents.index'))->viewData('page')['props'];

        // Both halves are named, and both are inside the same total.
        $this->assertSame(600 * 1024, $props['storage']['used']['documents']);
        $this->assertArrayHasKey('attachments', $props['storage']['used']);
        $this->assertSame(
            $props['storage']['used']['documents'] + $props['storage']['used']['attachments'],
            $props['storage']['used']['total'],
        );
    }

    // ─── deleting, and the bin ───────────────────────────────────────────

    public function test_deleting_frees_the_space_but_keeps_the_file_for_a_while(): void
    {
        $this->upload();
        $document = Document::first();

        $this->actingAs($this->ctx['user'])->delete(route('client.documents.destroy', $document->uuid));

        // Gone from the allowance immediately...
        $this->assertSame(0, (int) Document::where('workspace_id', $this->ctx['workspace']->id)->sum('size_bytes'));
        // ...but recoverable, because a contract deleted by mistake is a real one.
        $this->assertNotNull($document->fresh()?->deleted_at);
        $this->assertTrue($this->privateDisk()->exists($document->path));
    }

    public function test_the_purge_removes_the_file_once_the_bin_is_old_enough(): void
    {
        $this->upload();
        $document = Document::first();
        $path = $document->path;
        $document->delete();

        $this->artisan('documents:purge')->assertSuccessful();
        $this->assertTrue($this->privateDisk()->exists($path), 'still inside the thirty days');

        Document::withTrashed()->whereKey($document->id)->update(['deleted_at' => now()->subDays(31)]);
        $this->artisan('documents:purge')->assertSuccessful();

        $this->assertFalse($this->privateDisk()->exists($path));
        $this->assertNull(Document::withTrashed()->find($document->id));
    }
}
