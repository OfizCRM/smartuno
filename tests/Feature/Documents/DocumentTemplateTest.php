<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Services\OfficeTemplateFiller;
use App\Modules\Shared\Services\TextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * Templates, and the placeholder problem underneath them.
 *
 * Word stores text as runs and splits them wherever it likes, so a
 * `{{contact.company}}` a person typed in one go is routinely stored across
 * three of them. A search for the literal string finds nothing, the document
 * looks right, and the placeholder survives into the contract that gets sent.
 */
class DocumentTemplateTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateDisk();
        config([
            'services.onlyoffice.url' => 'http://localhost:8080',
            'services.onlyoffice.secret' => 'un-secret-suficient-de-lung-pentru-test',
        ]);
        $this->ctx = $this->createWorkspaceContext();
    }

    /**
     * A .docx whose placeholder is deliberately cut across three runs, with
     * formatting in the middle — what Word actually produces.
     */
    private function splitTemplate(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tpl_');
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $off = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $ct = 'http://schemas.openxmlformats.org/package/2006/content-types';

        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="'.$ct.'">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0"?><Relationships xmlns="'.$rel.'">'
            .'<Relationship Id="rId1" Type="'.$off.'/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="'.$w.'"><w:body>'
            .'<w:p><w:r><w:t>Contract cu </w:t></w:r>'
            .'<w:r><w:t>{{contact.</w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>com</w:t></w:r><w:r><w:t>pany}}</w:t></w:r>'
            .'<w:r><w:t>, CUI {{contact.tax_id}}.</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Necunoscut: {{contact.inexistent}}</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();

        $contents = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $contents;
    }

    private function template(?int $workspaceId = null): DocumentTemplate
    {
        $workspaceId ??= $this->ctx['workspace']->id;
        $this->privateDisk()->put('document-templates/contract.docx', $this->splitTemplate());

        return DocumentTemplate::create([
            'workspace_id' => $workspaceId,
            'name' => 'Contract cadru',
            'path' => 'document-templates/contract.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => 1000,
        ]);
    }

    private function contact(): Contact
    {
        return Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id,
            'company' => 'Clinica Nord SRL',
            'tax_id' => 'RO39117620',
        ]);
    }

    // ─── the filler itself ───────────────────────────────────────────────

    public function test_a_placeholder_word_split_across_runs_is_still_found(): void
    {
        $values = ['contact.company' => 'Clinica Nord SRL', 'contact.tax_id' => 'RO39117620'];

        $filled = app(OfficeTemplateFiller::class)->fill(
            'docx',
            $this->splitTemplate(),
            fn (string $token) => $values[$token] ?? null,
        );

        $text = app(TextExtractor::class)->extract('docx', $filled);

        $this->assertStringContainsString('Clinica Nord SRL', $text);
        $this->assertStringContainsString('RO39117620', $text);
        $this->assertStringNotContainsString('{{contact.company}}', $text);
    }

    public function test_an_unknown_token_is_left_where_it_is(): void
    {
        $filled = app(OfficeTemplateFiller::class)->fill('docx', $this->splitTemplate(), fn () => null);

        // A template with a typo should show the typo, not silently lose the line.
        $this->assertStringContainsString(
            '{{contact.inexistent}}',
            app(TextExtractor::class)->extract('docx', $filled),
        );
    }

    public function test_the_filled_file_is_still_a_valid_document(): void
    {
        $filled = app(OfficeTemplateFiller::class)->fill('docx', $this->splitTemplate(), fn () => 'X');

        $tmp = tempnam(sys_get_temp_dir(), 'check_');
        file_put_contents($tmp, $filled);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($tmp);

        // Removing the tags between the braces only works because the pairs
        // cancel. If they ever stop cancelling, this is what notices.
        $document = new \DOMDocument;
        $this->assertNotFalse(@$document->loadXML($xml), 'the document.xml no longer parses');
    }

    public function test_a_file_it_does_not_understand_comes_back_untouched(): void
    {
        $filler = app(OfficeTemplateFiller::class);

        $this->assertSame('orice', $filler->fill('pdf', 'orice', fn () => 'X'));
        $this->assertSame('nu sunt zip', $filler->fill('docx', 'nu sunt zip', fn () => 'X'));
    }

    // ─── making one, and using it ────────────────────────────────────────

    public function test_a_document_can_be_kept_as_a_template(): void
    {
        $this->privateDisk()->put('documents/sursa.docx', $this->splitTemplate());
        $document = Document::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'contract.docx',
            'path' => 'documents/sursa.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => 1000,
        ]);

        $this->actingAs($this->ctx['user'])
            ->post(route('client.documents.templates.store', $document->uuid), ['name' => 'Contract cadru'])
            ->assertRedirect();

        $template = DocumentTemplate::first();

        $this->assertSame('Contract cadru', $template->name);
        // A copy: editing the document later must not change every template
        // made from it.
        $this->assertNotSame($document->path, $template->path);
        $this->assertTrue($this->privateDisk()->exists($template->path));
        // A template is a contract with the client's terms already in it. The
        // copy is a third file on the disk and gets the same tripwire as the
        // document it was made from.
        $this->assertNotOnTheWebServersDisk($template->path, 'A document template');
    }

    public function test_a_new_document_from_a_template_arrives_with_the_client_filled_in(): void
    {
        $template = $this->template();
        $contact = $this->contact();

        $this->actingAs($this->ctx['user'])->post(route('client.documents.office.create'), [
            'name' => 'Contract Clinica Nord',
            'template_id' => $template->id,
            'contact_id' => $contact->id,
        ])->assertRedirect();

        $document = Document::first();
        $text = app(TextExtractor::class)->extract('docx', $this->privateDisk()->get($document->path));

        $this->assertSame($contact->id, $document->contact_id);
        $this->assertStringContainsString('Clinica Nord SRL', $text);
        $this->assertStringContainsString('RO39117620', $text);
    }

    public function test_without_a_client_the_template_is_copied_exactly(): void
    {
        $template = $this->template();

        $this->actingAs($this->ctx['user'])->post(route('client.documents.office.create'), [
            'name' => 'Contract gol',
            'template_id' => $template->id,
        ])->assertRedirect();

        // Byte for byte, placeholders and all: with nobody to fill them in,
        // touching the file could only make it worse. Asserting on the extracted
        // text would not do — the placeholder is split across runs there.
        $this->assertSame(
            $this->privateDisk()->get($template->path),
            $this->privateDisk()->get(Document::first()->path),
        );
    }

    public function test_a_template_from_another_workspace_is_not_used(): void
    {
        $intruder = $this->createWorkspaceContext();
        $template = $this->template($intruder['workspace']->id);

        $this->actingAs($this->ctx['user'])->post(route('client.documents.office.create'), [
            'name' => 'Ceva', 'template_id' => $template->id, 'kind' => 'word',
        ])->assertRedirect();

        // Falls back to a blank page rather than reaching into another tenant.
        $text = app(TextExtractor::class)->extract('docx', $this->privateDisk()->get(Document::first()->path));
        $this->assertStringNotContainsString('Contract cu', $text);
    }

    public function test_another_workspace_cannot_delete_a_template(): void
    {
        $template = $this->template();
        $intruder = $this->createWorkspaceContext();

        $this->actingAs($intruder['user'])
            ->delete(route('client.documents.templates.destroy', $template->id))
            ->assertForbidden();

        $this->assertNotNull($template->fresh());
    }
}
