<?php

namespace Tests\Feature\Documents;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\Documents\Jobs\ExtractDocumentTextJob;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Shared\Services\TextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * Reading what a document says, and keeping what it used to say.
 *
 * The extractor is the piece the chatbot was silently missing: a .docx is a zip
 * archive, and the knowledge base used to embed its raw bytes without a word of
 * complaint.
 */
class DocumentContentTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateDisk();
        $this->ctx = $this->createWorkspaceContext();
    }

    /** A real .docx — a zip of XML, which is exactly what makes this worth testing. */
    private function docx(string $paragraph): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx_');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="x"><w:body><w:p><w:r><w:t>'
            .htmlspecialchars($paragraph, ENT_XML1)
            .'</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $contents = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $contents;
    }

    private function xlsx(string $cell): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml',
            '<?xml version="1.0"?><sst xmlns="x"><si><t>'.htmlspecialchars($cell, ENT_XML1).'</t></si></sst>');
        $zip->close();
        $contents = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $contents;
    }

    // ─── the extractor ───────────────────────────────────────────────────

    public function test_a_word_document_gives_up_its_words(): void
    {
        $text = app(TextExtractor::class)->extract('docx', $this->docx('Contract de prestări servicii'));

        $this->assertStringContainsString('Contract de prestări servicii', $text);
        // Not the zip it physically is.
        $this->assertStringNotContainsString('PK', substr($text, 0, 4));
    }

    public function test_a_spreadsheet_gives_up_its_cells(): void
    {
        $this->assertStringContainsString(
            'Servicii stomatologice',
            app(TextExtractor::class)->extract('xlsx', $this->xlsx('Servicii stomatologice')),
        );
    }

    public function test_a_corrupt_file_is_empty_rather_than_an_error(): void
    {
        $this->assertSame('', app(TextExtractor::class)->extract('docx', 'nu sunt o arhiva'));
    }

    public function test_a_type_it_cannot_read_is_declined_up_front(): void
    {
        $extractor = app(TextExtractor::class);

        $this->assertTrue($extractor->supports('docx'));
        $this->assertFalse($extractor->supports('zip'));
        $this->assertFalse($extractor->supports('png'));
    }

    // ─── search inside the file ──────────────────────────────────────────

    public function test_a_document_is_found_by_a_phrase_inside_it(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.documents.store'), [
            'files' => [UploadedFile::fake()->createWithContent('anexa.txt', 'Termen de plată 15 zile de la emiterea facturii')],
        ]);

        $props = $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.index', ['search' => 'emiterea facturii']))
            ->viewData('page')['props'];

        // The filename says nothing about payment terms; the contents do.
        $this->assertCount(1, $props['documents']['data']);
        $this->assertSame('anexa.txt', $props['documents']['data'][0]['name']);
    }

    public function test_a_big_file_has_its_text_read_in_the_background(): void
    {
        Queue::fake();

        $this->actingAs($this->ctx['user'])->post(route('client.documents.store'), [
            // Over the inline threshold, with bytes that actually exist — a fake
            // that only reports its size would be read inline like any other.
            'files' => [UploadedFile::fake()->createWithContent('mare.txt', str_repeat('x', 3 * 1024 * 1024))],
        ]);

        Queue::assertPushed(ExtractDocumentTextJob::class);
    }

    // ─── the chatbot ─────────────────────────────────────────────────────

    public function test_a_document_can_be_handed_to_the_bot_and_taken_back(): void
    {
        Queue::fake();
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Preturi',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 1536,
            'status' => 'active',
        ]);
        $document = $this->uploadedDocument();

        $this->actingAs($this->ctx['user'])
            ->post(route('client.documents.kb.store', $document->uuid), ['kb_id' => $kb->id])
            ->assertRedirect();

        $document->refresh();
        $kbDocument = AiKbDocument::find($document->kb_document_id);

        $this->assertNotNull($kbDocument);
        $this->assertSame('file', $kbDocument->source_type);
        // The document itself, not its path. A bare path carries no idea which
        // disk it belongs to, and the indexing job used to pick the disk by
        // string-matching the "documents/" prefix — which breaks the moment the
        // private disk stops being the local one, silently indexing nothing.
        $this->assertSame(
            IndexDocumentJob::DOCUMENT_REF_PREFIX.$document->uuid,
            $kbDocument->source_ref,
        );

        $this->actingAs($this->ctx['user'])->delete(route('client.documents.kb.destroy', $document->uuid));

        $this->assertNull($document->fresh()->kb_document_id);
        $this->assertNull(AiKbDocument::find($kbDocument->id));
    }

    public function test_a_knowledge_base_from_another_workspace_is_refused(): void
    {
        $intruder = $this->createWorkspaceContext();
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $intruder['workspace']->id,
            'name' => 'Al lor',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 1536,
            'status' => 'active',
        ]);

        $this->actingAs($this->ctx['user'])
            ->post(route('client.documents.kb.store', $this->uploadedDocument()->uuid), ['kb_id' => $kb->id])
            ->assertNotFound();
    }

    // ─── versions ────────────────────────────────────────────────────────

    public function test_a_new_version_replaces_the_file_and_keeps_the_old_one(): void
    {
        $document = $this->uploadedDocument('prima versiune');
        $firstPath = $document->path;

        $this->actingAs($this->ctx['user'])->post(route('client.documents.versions.store', $document->uuid), [
            'file' => UploadedFile::fake()->createWithContent('contract.txt', 'a doua versiune'),
        ])->assertRedirect();

        $document->refresh();
        $version = DocumentVersion::where('document_id', $document->id)->first();

        $this->assertNotSame($firstPath, $document->path);
        $this->assertSame($firstPath, $version->path);
        $this->assertSame(1, $version->version);
        // The old file is still there — that is the point of the whole feature.
        $this->assertTrue($this->privateDisk()->exists($firstPath));
        // Two files now, the superseded one and the current one. Replacing a
        // document is the moment a second copy comes into existence, so it is
        // the moment worth checking that neither copy went somewhere public.
        $this->assertNotOnTheWebServersDisk($firstPath, 'A superseded version');
        $this->assertNotOnTheWebServersDisk($document->path, 'The current file');
        // And the text is re-read, so search follows the current file.
        $this->assertStringContainsString('a doua versiune', $document->content->text);
    }

    public function test_an_old_version_can_be_downloaded_but_never_shown_in_the_page(): void
    {
        $document = $this->uploadedDocument();
        $this->actingAs($this->ctx['user'])->post(route('client.documents.versions.store', $document->uuid), [
            'file' => UploadedFile::fake()->createWithContent('contract.txt', 'nou'),
        ]);
        $version = DocumentVersion::first();

        $response = $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.versions.show', [$document->uuid, $version->id]));

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_another_workspace_cannot_add_or_read_a_version(): void
    {
        $document = $this->uploadedDocument();
        $intruder = $this->createWorkspaceContext();

        $this->actingAs($intruder['user'])->post(route('client.documents.versions.store', $document->uuid), [
            'file' => UploadedFile::fake()->createWithContent('al-meu.txt', 'x'),
        ])->assertForbidden();

        $this->assertSame(0, DocumentVersion::count());
    }

    // ─── Word and Excel ──────────────────────────────────────────────────

    public function test_word_is_never_shown_as_a_page_and_stays_a_download(): void
    {
        $document = $this->uploadedDocument(name: 'contract.docx', mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        // There is no conversion any more: a .docx opens in the editor, which
        // reads the real file. Asking to display one here is a 404 — and the
        // download it always had still works.
        $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.file', ['document' => $document->uuid, 'preview' => 1]))
            ->assertNotFound();

        $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.file', $document->uuid))
            ->assertOk();
    }

    private function uploadedDocument(string $contents = 'continut', string $name = 'contract.txt', string $mime = 'text/plain'): Document
    {
        $this->actingAs($this->ctx['user'])->post(route('client.documents.store'), [
            'files' => [UploadedFile::fake()->createWithContent($name, $contents)],
        ]);

        $document = Document::latest('id')->first();
        // Fake uploads report their type from the extension; force the one the
        // test means when it matters.
        $document->update(['mime' => $mime, 'extension' => pathinfo($name, PATHINFO_EXTENSION)]);

        return $document->fresh();
    }
}
