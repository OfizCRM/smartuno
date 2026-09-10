<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\OnlyOfficeSession;
use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\PrivateFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * Half a migration, expressed.
 *
 * Nothing in this stage moves a byte. What it adds is the ability to SAY where a
 * byte is: `path` on its own is half an address — it names a place inside a disk
 * and never which disk — so the disk was answered by whatever the application
 * happened to be configured with at the moment it read. One global value, applied
 * retroactively to every row ever written. Flip it and every existing file 404s
 * at once, because the old rows are now being looked for in a bucket they were
 * never written to.
 *
 * There is no way to move a library of files without passing through a state
 * where some are on the old disk and some are on the new one. If the schema
 * cannot describe that state, there is no safe migration — only a cutover with
 * an outage in the middle and no way back.
 *
 * The case that forces the column to exist BEFORE anything moves is a document
 * edited during the window, and it is the middle test here. OfficeController
 * fetches the current bytes, writes new ones, preserves the old path as a
 * DocumentVersion and repoints the document. Run that while files are moving and
 * the version and the document are legitimately on DIFFERENT disks — an ordinary
 * outcome that the previous schema could not record, which means the preserved
 * version simply became unreadable.
 *
 * The `local` fallback is not a default anyone chose. Every row and every payload
 * entry written before this column existed is on `local`, nothing recorded that
 * fact anywhere, and there is nothing to backfill from — so "no disk recorded"
 * can only ever mean `local`, forever.
 */
class PrivateFileDiskColumnTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    /**
     * Stands in for the disk the private files will move TO.
     *
     * A fake, not a real bucket: Storage::fake() will happily invent a disk that
     * appears in no config file, rooted in its own temp directory, which is all
     * "somewhere that is not the current disk" has to mean for these tests.
     *
     * The NAME, though, is not arbitrary any more. It was `r2` — which is the
     * PUBLIC bucket's disk — and the attachment route now refuses to read a
     * private file from a disk private files are never written to, so naming
     * that one had this suite asserting the route would do something it must
     * not. `r2_private` is the name a migrated attachment actually carries, and
     * it is what makes the assertion "the entry's own disk is honoured" rather
     * than "any disk named in a row is honoured".
     */
    private const OTHER_DISK = 'r2_private';

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateDisk();
        Storage::fake(self::OTHER_DISK);
        $this->ctx = $this->createWorkspaceContext();
    }

    // ─── a write says where it put the bytes ─────────────────────────────

    public function test_an_uploaded_document_records_the_disk_its_bytes_went_to(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.documents.store'), [
            'files' => [UploadedFile::fake()->create('contract.pdf', 8, 'application/pdf')],
        ])->assertRedirect();

        $document = Document::firstOrFail();

        $this->assertSame(
            $this->privateDiskName(),
            $document->disk,
            'The upload stored a path but not the disk it wrote to. The row is then half an '.
            'address again, and the day the configured disk changes this file becomes '.
            'unreadable with no record anywhere of where it actually is.'
        );

        $this->assertTrue($this->privateDisk()->exists($document->path));
        $this->assertNotOnTheWebServersDisk($document->path, 'An uploaded document');
    }

    public function test_the_column_is_not_nullable_so_a_forgotten_write_site_is_loud(): void
    {
        $document = Document::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'contract.pdf',
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
        ]);

        // NOT NULL DEFAULT 'local', deliberately, rather than nullable. A NULL
        // would read as "we do not know which disk this is on", and there is no
        // way to find that out afterwards — the bytes are either in one place or
        // the other and nothing recorded which. A row that omits the disk gets
        // the only answer that can be true of a row written before the column
        // existed.
        $this->assertSame('local', $document->fresh()->disk);
    }

    // ─── a read follows the row, not the configuration ───────────────────

    public function test_a_download_reads_the_disk_the_row_names_not_the_configured_one(): void
    {
        // The state this whole stage exists to make expressible: this file has
        // been moved to the new disk, and the application is still configured to
        // write to the old one. Both are true at once for as long as a migration
        // takes.
        Storage::disk(self::OTHER_DISK)->put('documents/mutat.pdf', 'BYTES-ON-THE-NEW-DISK');

        $document = Document::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'contract.pdf',
            'path' => 'documents/mutat.pdf',
            'disk' => self::OTHER_DISK,
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 21,
        ]);

        // Nothing at that path on the configured disk. If the read ignores the
        // row and asks the configured disk, it finds nothing and 404s — which is
        // exactly what a migration would have done to every moved file.
        $this->assertFalse($this->privateDisk()->exists($document->path));

        $response = $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.file', $document->uuid));

        $response->assertOk();
        $this->assertSame(
            'BYTES-ON-THE-NEW-DISK',
            $response->getContent(),
            'The download did not read from the disk the row names. A read that resolves the '.
            'disk from configuration instead of from the row is the bug this column exists to '.
            'prevent: it works perfectly until the first file moves, then 404s everything that '.
            'has moved, with no error anywhere that says why.'
        );
    }

    public function test_a_row_written_before_the_column_existed_still_reads(): void
    {
        // The overwhelming majority of rows on the day this ships. They were
        // written with no disk recorded, they are all on `local`, and the default
        // is what says so.
        $store = app(PrivateFileStore::class);
        $entry = $store->put('documents', 'vechi.pdf', 'application/pdf', 'CONTINUT-VECHI');

        $document = Document::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'vechi.pdf',
            'path' => $entry['path'],
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 14,
        ]);

        $this->assertSame('local', $document->fresh()->disk);

        $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.file', $document->uuid))
            ->assertOk();
    }

    // ─── the concurrency case that forces the column ─────────────────────

    /**
     * A document edited while its file is on the other disk.
     *
     * OfficeController::callback() reads the current bytes, writes the new ones
     * through PrivateFileStore (so they land on whatever is configured NOW),
     * preserves the old path as a DocumentVersion, and repoints the document. Run
     * that during a migration window and the version and the document end up on
     * different disks — not as a bug, but as the correct description of where
     * two files actually are.
     *
     * Before this column that state could not be written down. The version row
     * kept the old path and the disk was resolved from configuration at read
     * time, so the moment the configuration moved on, every preserved version in
     * the product pointed into the wrong disk and the edit history was gone. That
     * is not recoverable after the fact: nothing anywhere recorded which disk
     * those bytes had been on.
     */
    public function test_a_version_records_the_disk_of_the_bytes_it_is_preserving(): void
    {
        Http::fake(['*' => Http::response('CONTINUT-NOU', 200)]);
        config([
            'services.onlyoffice.url' => 'http://localhost:8080',
            'services.onlyoffice.app_url' => 'http://host.docker.internal:8000',
            'services.onlyoffice.secret' => 'un-secret-suficient-de-lung-pentru-test',
        ]);

        Storage::disk(self::OTHER_DISK)->put('documents/proba.docx', 'CONTINUT-VECHI');

        $document = Document::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'contract.docx',
            'path' => 'documents/proba.docx',
            'disk' => self::OTHER_DISK,
            'mime' => 'application/octet-stream',
            'extension' => 'docx',
            'size_bytes' => 14,
        ]);
        $originalPath = $document->path;

        $office = app(OnlyOfficeSession::class);
        $url = $office->callbackUrl($document);
        $body = ['status' => 2, 'url' => 'http://onlyoffice/cache/files/rezultat.docx'];

        $this->postJson(
            parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY),
            $body + ['token' => $office->sign($body)]
        )->assertOk()->assertExactJson(['error' => 0]);

        $document->refresh();
        $version = DocumentVersion::where('document_id', $document->id)->firstOrFail();

        // The version keeps the OLD bytes, which are on the OTHER disk.
        $this->assertSame($originalPath, $version->path);
        $this->assertSame(
            self::OTHER_DISK,
            $version->disk,
            'The version recorded the path of the old file but not the disk it is on — it took '.
            "the configured disk instead of the document's. The old bytes are on [".self::OTHER_DISK.
            '] and this row now says they are somewhere else, so the previous contract is '.
            'unreadable and nothing recorded where it went.'
        );

        // ...while the new bytes went wherever the application writes today.
        $this->assertNotSame($originalPath, $document->path);
        $this->assertSame(
            $this->privateDiskName(),
            $document->disk,
            'The document kept its old disk after being written to a new one. The bytes and the '.
            'row now disagree, which is worse than the state before this column existed.'
        );
        $this->assertSame('CONTINUT-NOU', $this->privateDisk()->get($document->path));

        // The two rows disagreeing about the disk is the POINT — that is the
        // half-migrated state, correctly described.
        $this->assertNotSame(
            $version->disk,
            $document->disk,
            'This scenario is meant to leave the version and the document on different disks. '.
            'If they match, the test is no longer exercising the case the column exists for.'
        );

        // And the preserved version is still readable, which is the whole reason
        // any of this is worth doing.
        $this->actingAs($this->ctx['user'])
            ->get(route('client.documents.versions.show', [$document->uuid, $version->id]))
            ->assertOk();
    }

    // ─── attachments, which have no column to add ────────────────────────

    /**
     * An attachment entry has no row and no column: its path lives inside
     * messages.payload JSON. So the disk goes in the entry array, and every read
     * has to default a missing key — there is no migration that can add one to
     * the payloads already written, and no record anywhere of what it would say.
     */
    public function test_an_attachment_entry_records_the_disk_it_was_written_to(): void
    {
        $entry = app(AttachmentStore::class)->put('factura.pdf', 'application/pdf', 'TOTAL 249 lei');

        $this->assertSame($this->privateDiskName(), $entry['disk']);
        $this->assertNotOnTheWebServersDisk($entry['path'], 'A mail attachment');
    }

    public function test_an_over_cap_entry_still_says_which_disk_it_would_have_used(): void
    {
        $entry = app(AttachmentStore::class)->put(
            'film.bin',
            'application/octet-stream',
            str_repeat('x', AttachmentStore::MAX_FILE_BYTES + 1)
        );

        // Nothing was written, so there are no bytes to find — but the entry has
        // the same shape as one that was. A reader that must first ask which kind
        // of entry it is holding, before it knows which keys are safe to touch,
        // is a reader that will one day guess wrong.
        $this->assertFalse($entry['stored']);
        $this->assertNull($entry['path']);
        $this->assertArrayHasKey('disk', $entry);
    }

    public function test_an_attachment_written_before_the_key_existed_reads_as_local(): void
    {
        // Exactly the shape of every entry already sitting in messages.payload in
        // production: name, mime, size, path, stored — and no disk. There are
        // thousands of them and no migration can reach inside a JSON column to
        // add a key it has no way to compute. `local` is the only answer that can
        // be true of them, which is why it is the default rather than a guess.
        $store = app(PrivateFileStore::class);
        $legacy = $store->put('email-attachments', 'factura.pdf', 'application/pdf', 'TOTAL 249 lei');
        unset($legacy['disk']);

        $message = $this->messageWithAttachment($legacy);

        $this->assertArrayNotHasKey('disk', $message->payload['attachments'][0]);

        $this->actingAs($this->ctx['user'])->get(route('client.email.attachment', [
            'conversation' => $message->conversation->uuid,
            'message' => $message->id,
            'index' => 0,
        ]))->assertOk()->assertSee('TOTAL 249 lei');
    }

    public function test_an_attachment_on_the_other_disk_is_read_from_there(): void
    {
        Storage::disk(self::OTHER_DISK)->put('email-attachments/mutat.pdf', 'TOTAL 249 lei');

        $message = $this->messageWithAttachment([
            'name' => 'factura.pdf',
            'mime' => 'application/pdf',
            'size' => 13,
            'path' => 'email-attachments/mutat.pdf',
            'disk' => self::OTHER_DISK,
            'stored' => true,
        ]);

        // Not on the configured disk at all, so a read that ignores the entry's
        // own answer finds nothing.
        $this->assertFalse($this->privateDisk()->exists('email-attachments/mutat.pdf'));

        $this->actingAs($this->ctx['user'])->get(route('client.email.attachment', [
            'conversation' => $message->conversation->uuid,
            'message' => $message->id,
            'index' => 0,
        ]))->assertOk()->assertSee('TOTAL 249 lei');
    }

    private function messageWithAttachment(array $entry): Message
    {
        $mailbox = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap',
            'status' => 'active',
            'display_name' => 'office@firma.ro',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel_account_id' => $mailbox->id,
            'contact_id' => Contact::factory()->create([
                'workspace_id' => $this->ctx['workspace']->id,
                'email' => 'ana@client.ro',
            ])->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Vezi factura',
            'payload' => ['subject' => 'Factura', 'attachments' => [$entry]],
            'status' => 'delivered',
            'sent_at' => now(),
        ]);
    }
}
