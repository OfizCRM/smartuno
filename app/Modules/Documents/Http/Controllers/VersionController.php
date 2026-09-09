<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Jobs\ExtractDocumentTextJob;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentQuota;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Modules\Shared\Services\TextExtractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Replacing a document without losing what it said before.
 *
 * This is what makes "download it, edit it in Word, upload it again" safe. The
 * previous file is kept and stays downloadable; only the current one is what the
 * library shows and what the bot reads.
 */
class VersionController extends Controller
{
    private const MAX_FILE_KB = 25600;

    private const ALLOWED = 'pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,png,jpg,jpeg,gif,webp,zip';

    private const DIRECTORY = 'documents';

    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly DocumentQuota $quota,
        private readonly TextExtractor $extractor,
    ) {}

    public function store(Request $request, Document $document): RedirectResponse
    {
        $this->authorise($request, $document);

        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_FILE_KB, 'mimes:'.self::ALLOWED],
        ]);

        $file = $request->file('file');
        $workspaceId = (int) $document->workspace_id;

        // Both copies live on afterwards, so both have to fit.
        if (! $this->quota->fits($workspaceId, $file->getSize() ?: 0)) {
            return back()->with('error', __('No room left for :name. Free up space or move to a larger plan.', [
                'name' => $file->getClientOriginalName(),
            ]));
        }

        $entry = $this->files->put(
            self::DIRECTORY,
            (string) $file->getClientOriginalName(),
            (string) ($file->getMimeType() ?: 'application/octet-stream'),
            (string) file_get_contents($file->getRealPath()),
        );

        DocumentVersion::create([
            'document_id' => $document->id,
            'version' => (int) DocumentVersion::where('document_id', $document->id)->max('version') + 1,
            'name' => $document->name,
            'path' => $document->path,
            'mime' => $document->mime,
            'size_bytes' => $document->size_bytes,
            'created_by' => $document->created_by,
        ]);

        $document->update([
            // The document keeps its own name: it is the same contract, in a
            // newer state, and renaming it on every upload would break the link
            // people have in their heads.
            'path' => $entry['path'],
            'mime' => $entry['mime'],
            'extension' => pathinfo($entry['path'], PATHINFO_EXTENSION),
            'size_bytes' => $entry['size'],
        ]);

        if ($this->extractor->supports($document->extension)) {
            ExtractDocumentTextJob::store($document, $this->extractor->extract(
                $document->extension,
                (string) file_get_contents($file->getRealPath()),
            ));
        }

        $this->quota->forget($workspaceId);

        return back()->with('success', __('New version saved.'));
    }

    /** An older file, always as a download — nothing here is shown in the page. */
    public function show(Request $request, Document $document, DocumentVersion $version): HttpResponse
    {
        $this->authorise($request, $document);
        abort_unless((int) $version->document_id === (int) $document->id, 404);

        $contents = $this->files->contents($version->path);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $version->name,
                'document',
            ),
        ]);
    }

    private function authorise(Request $request, Document $document): void
    {
        abort_unless((int) $document->workspace_id === (int) $request->user()->workspace_id, 403);
    }
}
