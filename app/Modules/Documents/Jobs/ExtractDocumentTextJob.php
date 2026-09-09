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

        $contents = $files->contents($document->path);

        if ($contents === null) {
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
