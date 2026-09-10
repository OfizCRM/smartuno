<?php

namespace App\Modules\Documents\Jobs;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentContent;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Modules\Shared\Services\TextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reads a document so it can be searched by what it says.
 *
 * Only used for the big ones. Anything small enough is read during the upload
 * itself, because a search that quietly does not work until a queue worker
 * happens to be running is worse than an upload that takes another moment.
 */
class ExtractDocumentTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $documentId) {}

    public function handle(PrivateFileStore $files, TextExtractor $extractor): void
    {
        $document = Document::find($this->documentId);

        if (! $document || ! $extractor->supports($document->extension)) {
            return;
        }

        $contents = $files->contents($document->path, $document->disk);

        if ($contents === null) {
            // Nobody is waiting on this one, and the document itself is fine —
            // it still opens and still downloads; only searching by what it says
            // will not find it. So the job ends here rather than failing and
            // spending its retry on a file that, as far as this can tell, is not
            // coming back. The line is what turns a document that is quietly
            // unfindable into one somebody can go and look at.
            Log::warning('Document file is missing from storage, text not extracted', [
                'feature' => 'documents.extract_text',
                'workspace_id' => (int) $document->workspace_id,
                'document_id' => $document->id,
                'path' => $document->path,
            ]);

            return;
        }

        self::store($document, $extractor->extract($document->extension, $contents));
    }

    /** Shared with the inline path taken for small files. */
    public static function store(Document $document, string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        DocumentContent::updateOrCreate(
            ['document_id' => $document->id],
            ['text' => $text, 'extracted_at' => now()],
        );
    }
}
