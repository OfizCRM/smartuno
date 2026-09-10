<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFolder;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\OnlyOfficeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * Opening a document in ONLYOFFICE.
 *
 * The Document Server has no session, so a signature does the work a session
 * would. These tests are about that seam: what the signature covers, what it
 * refuses, and the caching key that decides whether an edit is ever seen.
 */
class OnlyOfficeTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateDisk();
        config([
            'services.onlyoffice.url' => 'http://localhost:8080',
            'services.onlyoffice.app_url' => 'http://host.docker.internal:8000',
            'services.onlyoffice.secret' => 'un-secret-suficient-de-lung-pentru-test',
        ]);
        $this->ctx = $this->createWorkspaceContext();
    }

    private function document(?int $workspaceId = null, string $extension = 'docx'): Document
    {
        $workspaceId ??= $this->ctx['workspace']->id;
        $this->privateDisk()->put("documents/proba.{$extension}", 'CONTINUT');

        return Document::create([
            'workspace_id' => $workspaceId,
            'name' => "contract.{$extension}",
            'path' => "documents/proba.{$extension}",
            'mime' => 'application/octet-stream',
            'extension' => $extension,
            'size_bytes' => 8,
        ]);
    }

    private function office(): OnlyOfficeSession
    {
        return app(OnlyOfficeSession::class);
    }

    // ─── the signed configuration ────────────────────────────────────────

    public function test_the_configuration_carries_its_own_signature(): void
    {
        $config = $this->office()->config($this->document(), $this->ctx['user'], editable: false);

        $this->assertArrayHasKey('token', $config);

        $payload = $this->office()->verify($config['token']);

        // The whole object is signed, so a page cannot ask for another document
        // by editing the configuration in devtools.
        $this->assertNotNull($payload);
        $this->assertSame($config['document']['key'], $payload['document']['key']);
        $this->assertSame($config['document']['url'], $payload['document']['url']);
    }

    public function test_a_tampered_token_is_refused(): void
    {
        $token = $this->office()->sign(['document' => ['key' => 'abc']]);
        [$header, $payload, $signature] = explode('.', $token);

        $this->assertNull($this->office()->verify($header.'.'.$payload.'.'.strrev($signature)));
        $this->assertNull($this->office()->verify('nu-e-un-token'));
        $this->assertNull($this->office()->verify(null));
    }

    public function test_a_token_signed_with_another_secret_is_refused(): void
    {
        $token = $this->office()->sign(['document' => ['key' => 'abc']]);

        config(['services.onlyoffice.secret' => 'alt-secret-cu-totul']);

        $this->assertNull($this->office()->verify($token));
    }

    public function test_the_file_url_points_at_the_address_the_container_can_reach(): void
    {
        $url = $this->office()->downloadUrl($this->document());

        // Not the browser's address: a container's localhost is the container.
        $this->assertStringStartsWith('http://host.docker.internal:8000/', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    // ─── the caching key ─────────────────────────────────────────────────

    public function test_the_key_changes_when_a_new_version_is_written(): void
    {
        $document = $this->document();
        $before = $this->office()->key($document);

        DocumentVersion::create([
            'document_id' => $document->id,
            'version' => 1,
            'name' => 'contract.docx',
            'path' => 'documents/vechi.docx',
            'mime' => 'application/octet-stream',
            'size_bytes' => 8,
        ]);

        // Same key and the Document Server serves the copy it already has, while
        // the editor looks perfectly fine.
        $this->assertNotSame($before, $this->office()->key($document->fresh()));
    }

    public function test_the_key_is_within_what_the_server_accepts(): void
    {
        $key = $this->office()->key($this->document());

        $this->assertLessThanOrEqual(128, strlen($key));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $key);
    }

    // ─── which files it opens ────────────────────────────────────────────

    #[DataProvider('fileTypes')]
    public function test_it_knows_what_it_can_open_and_change(string $extension, bool $opens, bool $edits): void
    {
        $this->assertSame($opens, $this->office()->opens($extension));
        $this->assertSame($edits, $this->office()->edits($extension));
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function fileTypes(): array
    {
        return [
            'word' => ['docx', true, true],
            'excel' => ['xlsx', true, true],
            'powerpoint' => ['pptx', true, true],
            // Opens for reading, but is not a format it writes back.
            'pdf' => ['pdf', true, false],
            'archive' => ['zip', false, false],
            'image' => ['png', false, false],
        ];
    }

    // ─── the page, and who may reach it ──────────────────────────────────

    public function test_the_owner_gets_an_editor_page(): void
    {
        $document = $this->document();

        $props = $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.office', $document->uuid))
            ->viewData('page')['props'];

        $this->assertSame('http://localhost:8080', $props['serverUrl']);
        $this->assertSame('word', $props['config']['documentType']);
        $this->assertSame('edit', $props['config']['editorConfig']['mode']);
        $this->assertTrue($props['config']['document']['permissions']['edit']);
        $this->assertNotNull($props['config']['editorConfig']['callbackUrl']);
    }

    public function test_another_workspace_cannot_open_it(): void
    {
        $document = $this->document();
        $intruder = $this->createWorkspaceContext();

        $this->actingAs($intruder['user'])
            ->get(route('client.documents.office', $document->uuid))
            ->assertForbidden();
    }

    public function test_a_file_it_cannot_open_sends_you_back_with_a_reason(): void
    {
        $document = $this->document(extension: 'zip');

        $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.office', $document->uuid))
            ->assertRedirect(route('client.documents.index'))
            ->assertSessionHas('error');
    }

    public function test_the_page_is_refused_while_the_editor_is_not_configured(): void
    {
        config(['services.onlyoffice.url' => '', 'services.onlyoffice.secret' => '']);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.office', $this->document()->uuid))
            ->assertRedirect(route('client.documents.index'));
    }

    // ─── the download the Document Server makes ──────────────────────────

    public function test_the_document_server_can_collect_the_file_with_a_valid_signature(): void
    {
        $document = $this->document();
        // Exactly the path the container is given, minus the host it uses.
        $path = parse_url($this->office()->downloadUrl($document), PHP_URL_PATH)
            .'?'.parse_url($this->office()->downloadUrl($document), PHP_URL_QUERY);

        // No session at all: the signature stands in for it.
        $response = $this->get($path);

        $response->assertOk();
        $this->assertSame('CONTINUT', $response->getContent());
    }

    public function test_an_unsigned_or_altered_url_gets_nothing(): void
    {
        $document = $this->document();

        $this->get(route('documents.office.download', ['document' => $document->uuid], absolute: false))
            ->assertForbidden();

        $signed = $this->office()->downloadUrl($document);
        $tampered = str_replace('signature=', 'signature=0', parse_url($signed, PHP_URL_PATH).'?'.parse_url($signed, PHP_URL_QUERY));

        $this->get($tampered)->assertForbidden();
    }

    public function test_an_expired_signature_gets_nothing(): void
    {
        $document = $this->document();
        $signed = $this->office()->downloadUrl($document);
        $path = parse_url($signed, PHP_URL_PATH).'?'.parse_url($signed, PHP_URL_QUERY);

        $this->travel(31)->minutes();

        $this->get($path)->assertForbidden();
    }

    // ─── the save coming back ────────────────────────────────────────────

    /** The shape the Document Server posts, signed the way it signs it. */
    private function postCallback(Document $document, array $body, bool $signToken = true): TestResponse
    {
        $url = $this->office()->callbackUrl($document);
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        return $this->postJson($path, $signToken
            ? $body + ['token' => $this->office()->sign($body)]
            : $body);
    }

    public function test_a_finished_edit_becomes_the_current_file_and_a_version(): void
    {
        Http::fake(['*' => Http::response('CONTINUT-NOU', 200)]);
        $document = $this->document();
        $originalPath = $document->path;

        $this->postCallback($document, [
            'status' => 2,
            'url' => 'http://onlyoffice/cache/files/rezultat.docx',
        ])->assertOk()->assertExactJson(['error' => 0]);

        $document->refresh();

        $this->assertNotSame($originalPath, $document->path);
        $this->assertSame('CONTINUT-NOU', $this->privateDisk()->get($document->path));
        // Nothing overwrites without leaving what was there behind.
        $version = DocumentVersion::where('document_id', $document->id)->first();
        $this->assertSame($originalPath, $version->path);
        $this->assertSame(1, $version->version);
        // The editor writes back over an unauthenticated container callback, so
        // this is the one write in the module that no logged-in user is standing
        // behind. Both the new file and the preserved one stay off the public
        // disk.
        $this->assertNotOnTheWebServersDisk($document->path, 'An edited document');
        $this->assertNotOnTheWebServersDisk($version->path, 'The preserved version');
    }

    public function test_the_saved_text_is_reindexed_so_search_follows_the_edit(): void
    {
        Http::fake(['*' => Http::response('clauza de reziliere adaugata', 200)]);
        $document = $this->document(extension: 'txt');

        $this->postCallback($document, ['status' => 2, 'url' => 'http://onlyoffice/cache/x.txt'])->assertOk();

        $this->assertStringContainsString('clauza de reziliere', $document->fresh()->content->text);
    }

    public function test_the_url_it_gives_us_is_rewritten_to_one_we_can_reach(): void
    {
        // The Document Server builds these from its own idea of where it lives,
        // which inside a container is not where we call it from.
        $this->assertSame(
            'http://localhost:8080/cache/files/x.docx',
            $this->office()->reachable('http://onlyoffice-container/cache/files/x.docx'),
        );
    }

    public function test_a_callback_without_a_valid_token_changes_nothing(): void
    {
        Http::fake(['*' => Http::response('NU-TREBUIE-SA-AJUNGA', 200)]);
        $document = $this->document();
        $originalPath = $document->path;

        $this->postCallback($document, ['status' => 2, 'url' => 'http://onlyoffice/cache/x.docx'], signToken: false)
            ->assertForbidden();

        $this->assertSame($originalPath, $document->fresh()->path);
        $this->assertSame(0, DocumentVersion::count());
    }

    public function test_a_callback_on_an_unsigned_url_changes_nothing(): void
    {
        $document = $this->document();

        $this->postJson(route('documents.office.callback', ['document' => $document->uuid], absolute: false), [
            'status' => 2,
            'url' => 'http://onlyoffice/cache/x.docx',
        ])->assertForbidden();

        $this->assertSame(0, DocumentVersion::count());
    }

    #[DataProvider('quietStatuses')]
    public function test_the_statuses_that_need_nothing_from_us_are_answered_and_ignored(int $status): void
    {
        $document = $this->document();
        $originalPath = $document->path;

        $this->postCallback($document, ['status' => $status])->assertOk()->assertExactJson(['error' => 0]);

        $this->assertSame($originalPath, $document->fresh()->path);
        $this->assertSame(0, DocumentVersion::count());
    }

    /** @return array<string, array{int}> */
    public static function quietStatuses(): array
    {
        return [
            'still being edited' => [1],
            'closed with no changes' => [4],
            // Their side failed. Nothing of ours to write, and the reply must
            // still be {"error": 0} or the person sees an error we did not cause.
            'their saving error' => [3],
        ];
    }

    // ─── starting from nothing ───────────────────────────────────────────

    /** @return array<string, array{string, string, string}> */
    public static function newDocuments(): array
    {
        return [
            'a Word document' => ['word', 'docx', 'wordprocessingml'],
            'a spreadsheet' => ['cell', 'xlsx', 'spreadsheetml'],
        ];
    }

    #[DataProvider('newDocuments')]
    public function test_a_blank_document_is_created_and_opens_straight_in_the_editor(string $kind, string $extension, string $mimePart): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.documents.office.create'), [
            'name' => 'Contract Clinica Nord',
            'kind' => $kind,
        ])->assertRedirect();

        $document = Document::first();

        $this->assertSame('Contract Clinica Nord.'.$extension, $document->name);
        $this->assertSame($extension, $document->extension);
        $this->assertStringContainsString($mimePart, $document->mime);
        $this->assertSame('generated', $document->source);
        $this->assertTrue($this->privateDisk()->exists($document->path));
        $this->assertNotOnTheWebServersDisk($document->path, 'A document created in the editor');
    }

    public function test_the_blank_files_shipped_with_the_module_are_real_office_files(): void
    {
        // A zip whose parts are named the way Word and Excel expect. If this ever
        // fails, every new document opens to an error inside the editor.
        foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml'] as $extension => $part) {
            $path = app_path("Modules/Documents/resources/blank/blank.{$extension}");

            $this->assertFileExists($path);

            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true, "blank.{$extension} is not a zip");
            $this->assertNotFalse($zip->getFromName('[Content_Types].xml'), "blank.{$extension} has no content types");
            $this->assertNotFalse($zip->getFromName($part), "blank.{$extension} has no {$part}");
            $zip->close();
        }
    }

    public function test_a_new_document_lands_in_the_folder_being_looked_at(): void
    {
        $folder = DocumentFolder::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Contracte',
        ]);

        $this->actingAs($this->ctx['user'])->post(route('client.documents.office.create'), [
            'name' => 'Anexa', 'kind' => 'word', 'folder_id' => $folder->id,
        ]);

        $this->assertSame($folder->id, Document::first()->folder_id);
    }

    public function test_a_folder_from_another_workspace_is_not_used(): void
    {
        $intruder = $this->createWorkspaceContext();
        $folder = DocumentFolder::create([
            'workspace_id' => $intruder['workspace']->id,
            'name' => 'Al lor',
        ]);

        $this->actingAs($this->ctx['user'])->post(route('client.documents.office.create'), [
            'name' => 'Anexa', 'kind' => 'word', 'folder_id' => $folder->id,
        ]);

        $this->assertNull(Document::first()->folder_id);
    }

    public function test_nothing_is_created_while_the_editor_is_unconfigured(): void
    {
        config(['services.onlyoffice.url' => '', 'services.onlyoffice.secret' => '']);

        $this->actingAs($this->ctx['user'])->post(route('client.documents.office.create'), [
            'name' => 'Anexa', 'kind' => 'word',
        ])->assertSessionHas('error');

        $this->assertSame(0, Document::count());
    }
}
