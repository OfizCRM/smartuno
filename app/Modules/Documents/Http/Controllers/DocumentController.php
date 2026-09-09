<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\Documents\Jobs\ExtractDocumentTextJob;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFolder;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Services\DocumentImporter;
use App\Modules\Documents\Services\DocumentQuota;
use App\Modules\Documents\Services\OnlyOfficeSession;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Modules\Shared\Services\TextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * The document library.
 *
 * Files live on the private disk and are only ever served back through this
 * controller, which checks the workspace first — the same rule as email
 * attachments, for the same reason: a contract must not have a URL that works
 * for whoever ends up holding it.
 */
class DocumentController extends Controller
{
    /** Per file. The drop zone says the same number. */
    private const MAX_FILE_KB = 25600;

    /** Where in the private disk these go. */
    private const DIRECTORY = 'documents';

    /**
     * What may be uploaded. No HTML, no SVG, nothing a browser executes — the
     * same posture as the inbox, widened to the formats an office actually
     * files.
     */
    private const ALLOWED = 'pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,png,jpg,jpeg,gif,webp,zip';

    /**
     * Under this, the text is read during the request. A search that quietly
     * does not work until a queue worker happens to be running is worse than an
     * upload that takes another moment.
     */
    private const INLINE_EXTRACT_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly DocumentQuota $quota,
        private readonly DocumentImporter $importer,
        private readonly TextExtractor $extractor,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = (int) $request->user()->workspace_id;

        $filters = [
            'folder_id' => $request->integer('folder_id') ?: null,
            'kind' => $request->string('kind')->toString() ?: null,
            'search' => trim($request->string('search')->toString()) ?: null,
        ];

        $documents = Document::where('workspace_id', $workspaceId)
            ->with(['folder:id,name', 'contact:id,first_name,last_name,company', 'creator:id,name', 'versions'])
            ->narrowed($filters)
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'folders' => $this->folderTree($workspaceId),
            'counts' => $this->counts($workspaceId),
            'storage' => [
                'used' => $this->quota->usage($workspaceId),
                'limit' => $this->quota->limitBytes($workspaceId),
            ],
            'filters' => $filters,
            'maxFileBytes' => self::MAX_FILE_KB * 1024,
            // Whether Word and Excel can be shown in the page at all. Without it
            // the eye would open a 404, which is worse than not offering it.
            // Whether the row offers "open in editor" at all. A button that
            // leads to a page which redirects straight back is worse than none.
            'canUseOffice' => app(OnlyOfficeSession::class)->available(),
            'templates' => DocumentTemplate::where('workspace_id', $workspaceId)
                ->orderBy('name')
                ->get(['id', 'name', 'extension']),
            // For the "let the bot use this" switch. Empty when the tenant has
            // not built one, and the switch then simply is not offered.
            'knowledgeBases' => AiKnowledgeBase::where('workspace_id', $workspaceId)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;

        $data = $request->validate([
            'files' => ['required', 'array', 'max:20'],
            'files.*' => ['file', 'max:'.self::MAX_FILE_KB, 'mimes:'.self::ALLOWED],
            'folder_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
        ]);

        $folderId = $this->ownedFolderId($workspaceId, $data['folder_id'] ?? null);
        $contactId = $this->ownedContactId($workspaceId, $data['contact_id'] ?? null);

        $stored = [];
        foreach ($request->file('files') as $file) {
            $bytes = $file->getSize() ?: 0;

            if (! $this->quota->fits($workspaceId, $bytes)) {
                // Refused before anything is written, and what did fit stays.
                // Rolling the whole batch back would punish the files that were
                // fine; leaving the disk over the line would make the limit a
                // suggestion.
                return $this->overQuota($request, $file->getClientOriginalName(), count($stored));
            }

            $entry = $this->files->put(
                self::DIRECTORY,
                (string) $file->getClientOriginalName(),
                (string) ($file->getMimeType() ?: 'application/octet-stream'),
                (string) file_get_contents($file->getRealPath()),
            );

            $document = Document::create([
                'workspace_id' => $workspaceId,
                'folder_id' => $folderId,
                'contact_id' => $contactId,
                'name' => $entry['name'],
                'path' => $entry['path'],
                'mime' => $entry['mime'],
                'extension' => pathinfo($entry['path'], PATHINFO_EXTENSION),
                'size_bytes' => $entry['size'],
                'source' => 'upload',
                'created_by' => $request->user()->id,
            ]);

            $this->readText($document, (string) file_get_contents($file->getRealPath()));
            $stored[] = $document;
        }

        $this->quota->forget($workspaceId);

        return back()->with('success', trans_choice(':count file(s) uploaded.', count($stored), ['count' => count($stored)]));
    }

    /**
     * A short list for the picker in the inbox composer.
     *
     * Only what a chooser needs — the list screen's full payload would carry
     * folders, counts and the storage figure into a dropdown.
     */
    public function list(Request $request): JsonResponse
    {
        $documents = Document::where('workspace_id', $request->user()->workspace_id)
            ->narrowed(['search' => trim($request->string('q')->toString()) ?: null])
            ->latest('created_at')
            ->limit(30)
            ->get(['uuid', 'name', 'path', 'size_bytes', 'created_at']);

        return response()->json($documents);
    }

    /**
     * File an attachment from a thread into the library.
     *
     * The invoice a supplier emailed belongs in the same place as the one that
     * was uploaded by hand; without this the two live in different halves of the
     * product and only one of them can be found again.
     */
    public function fromMessage(Request $request): RedirectResponse|JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;

        $data = $request->validate([
            'conversation' => ['required', 'string', 'uuid'],
            'message_id' => ['required', 'integer'],
            'index' => ['required', 'integer', 'min:0'],
            'folder_id' => ['nullable', 'integer'],
        ]);

        $conversation = Conversation::where('workspace_id', $workspaceId)
            ->where('uuid', $data['conversation'])
            ->firstOrFail();

        $message = Message::where('conversation_id', $conversation->id)
            ->whereKey($data['message_id'])
            ->firstOrFail();

        $attachment = (($message->getAttribute('payload') ?? [])['attachments'] ?? [])[$data['index']] ?? null;
        abort_unless(is_array($attachment) && ! empty($attachment['path']), 404);

        $document = $this->importer->fromAttachment(
            $conversation,
            $message,
            $attachment,
            $this->ownedFolderId($workspaceId, $data['folder_id'] ?? null),
            (int) $request->user()->id,
        );

        if (! $document) {
            return $this->overQuota($request, (string) ($attachment['name'] ?? ''), 0);
        }

        $message = __('Saved to documents.');

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'document' => ['uuid' => $document->uuid, 'name' => $document->name]])
            : back()->with('success', $message);
    }

    /**
     * Hand one document back — as a download, or shown in the page when the type
     * is one of the few that is safe to display.
     */
    public function file(Request $request, Document $document): HttpResponse
    {
        $this->authorise($request, $document);

        $contents = $this->files->contents($document->path);
        abort_if($contents === null, 404);

        if (! $request->boolean('preview')) {
            return $this->respond($contents, 'application/octet-stream', $document->name, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        }

        // From the extension we stored it under, never the sender's claim. A
        // type outside the list is answered 404 rather than quietly downloaded:
        // the caller asked to display something that must not be displayed.
        $mime = $this->files->previewMimeFor($document->path);

        if ($mime !== null) {
            return $this->respond($contents, $mime, $document->name, ResponseHeaderBag::DISPOSITION_INLINE);
        }

        // Word, Excel and PowerPoint are not converted for display: they open
        // in the editor, which reads the real file. Asking to show one here is
        // answered 404 and the caller offers the download it always had.
        abort(404);
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        $this->authorise($request, $document);
        $workspaceId = (int) $request->user()->workspace_id;

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'folder_id' => ['sometimes', 'nullable', 'integer'],
            'contact_id' => ['sometimes', 'nullable', 'integer'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'remind_days' => ['sometimes', 'nullable', 'array', 'max:4'],
            'remind_days.*' => ['integer', 'min:0', 'max:365'],
        ]);

        $updates = [];
        if (array_key_exists('name', $data)) {
            $updates['name'] = $this->files->safeName($data['name']);
        }
        if (array_key_exists('folder_id', $data)) {
            $updates['folder_id'] = $this->ownedFolderId($workspaceId, $data['folder_id']);
        }
        if (array_key_exists('contact_id', $data)) {
            $updates['contact_id'] = $this->ownedContactId($workspaceId, $data['contact_id']);
        }
        if (array_key_exists('expires_at', $data) || array_key_exists('remind_days', $data)) {
            $updates['expires_at'] = $data['expires_at'] ?? null;
            $updates['remind_days'] = array_values(array_unique(array_map('intval', $data['remind_days'] ?? [])));
            // Moving the date makes the old warnings due again: a contract
            // pushed out by a year has not been announced for its new date.
            $updates['reminders_sent'] = [];
        }

        $document->update($updates);

        return back()->with('success', __('Document updated.'));
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorise($request, $document);

        // Soft: the file stays on the disk and the row keeps its history, so a
        // contract deleted by mistake is recoverable. It stops counting against
        // the allowance immediately, and documents:purge removes it for good
        // after thirty days.
        $document->delete();
        $this->quota->forget((int) $request->user()->workspace_id);

        return back()->with('success', __('Document deleted.'));
    }

    private function respond(string $contents, string $mime, string $name, string $disposition): HttpResponse
    {
        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition($disposition, $name, 'document'),
        ];

        if ($disposition === ResponseHeaderBag::DISPOSITION_INLINE) {
            // The same policy Laravel puts on its own storage route. `sandbox` is
            // what stops script inside a PDF running against the session of the
            // person who opened it.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        return response($contents, 200, $headers);
    }

    /** @return array<int, array<string, mixed>> */
    private function folderTree(int $workspaceId): array
    {
        $counts = Document::where('workspace_id', $workspaceId)
            ->whereNotNull('folder_id')
            ->groupBy('folder_id')
            ->selectRaw('folder_id, count(*) as aggregate')
            ->pluck('aggregate', 'folder_id');

        return DocumentFolder::where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name'])
            ->map(fn (DocumentFolder $f) => [
                'id' => $f->id,
                'parent_id' => $f->parent_id,
                'name' => $f->name,
                'count' => (int) ($counts[$f->id] ?? 0),
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function counts(int $workspaceId): array
    {
        $byExtension = Document::where('workspace_id', $workspaceId)
            ->groupBy('extension')
            ->selectRaw('extension, count(*) as aggregate')
            ->pluck('aggregate', 'extension');

        $counts = ['all' => (int) $byExtension->sum()];
        foreach (Document::KINDS as $kind => $extensions) {
            $counts[$kind] = (int) collect($extensions)->sum(fn (string $ext) => (int) ($byExtension[$ext] ?? 0));
        }

        return $counts;
    }

    /**
     * Make the document searchable by what it says.
     *
     * Never allowed to fail an upload: a file whose text cannot be read is still
     * a file the tenant wanted kept.
     */
    private function readText(Document $document, string $contents): void
    {
        if (! $this->extractor->supports($document->extension)) {
            return;
        }

        if ($document->size_bytes > self::INLINE_EXTRACT_BYTES) {
            ExtractDocumentTextJob::dispatch($document->id);

            return;
        }

        try {
            ExtractDocumentTextJob::store($document, $this->extractor->extract($document->extension, $contents));
        } catch (\Throwable) {
            ExtractDocumentTextJob::dispatch($document->id);
        }
    }

    /** A folder id only if it belongs to this workspace; otherwise none. */
    private function ownedFolderId(int $workspaceId, ?int $folderId): ?int
    {
        if (! $folderId) {
            return null;
        }

        return DocumentFolder::where('workspace_id', $workspaceId)->whereKey($folderId)->value('id');
    }

    private function ownedContactId(int $workspaceId, ?int $contactId): ?int
    {
        if (! $contactId) {
            return null;
        }

        return Contact::where('workspace_id', $workspaceId)->whereKey($contactId)->value('id');
    }

    private function overQuota(Request $request, string $filename, int $alreadyStored): RedirectResponse|JsonResponse
    {
        if ($alreadyStored > 0) {
            $this->quota->forget((int) $request->user()->workspace_id);
        }

        $message = __('No room left for :name. Free up space or move to a larger plan.', ['name' => $filename]);

        return $request->expectsJson()
            ? response()->json(['error' => $message], 422)
            : back()->with('error', $message);
    }

    private function authorise(Request $request, Document $document): void
    {
        abort_unless((int) $document->workspace_id === (int) $request->user()->workspace_id, 403);
    }
}
