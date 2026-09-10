<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Documents\Jobs\ExtractDocumentTextJob;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFolder;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentQuota;
use App\Modules\Documents\Services\OnlyOfficeSession;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Services\OfficeTemplateFiller;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Modules\Shared\Services\TextExtractor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Opening a document in ONLYOFFICE.
 *
 * Two audiences, and they are not the same: the person, who arrives with a
 * session and is checked against the workspace, and the Document Server, which
 * arrives with no session at all and is checked by a signature.
 */
class OfficeController extends Controller
{
    /** Where the file goes when a save comes back. */
    private const DIRECTORY = 'documents';

    public function __construct(
        private readonly OnlyOfficeSession $office,
        private readonly PrivateFileStore $files,
        private readonly DocumentQuota $quota,
        private readonly TextExtractor $extractor,
        private readonly OfficeTemplateFiller $filler,
        private readonly CampaignPersonalizer $personalizer,
    ) {}

    /**
     * A new, empty Word document or spreadsheet.
     *
     * The blank files are shipped with the module rather than produced on the
     * fly: an empty .docx is still a zip of XML parts, and generating one per
     * request would be work with no upside.
     */
    public function create(Request $request): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;

        if (! $this->office->available()) {
            return back()->with('error', __('The document editor is not configured. See ONLYOFFICE_URL in the README.'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'kind' => ['required_without:template_id', 'nullable', 'in:word,cell'],
            'template_id' => ['nullable', 'integer'],
            'folder_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
        ]);

        $template = ! empty($data['template_id'])
            ? DocumentTemplate::where('workspace_id', $workspaceId)->find($data['template_id'])
            : null;

        $contactId = $this->ownedId(Contact::class, $workspaceId, $data['contact_id'] ?? null);
        $contact = $contactId ? Contact::find($contactId) : null;

        if ($template) {
            $extension = $template->extension;
            $contents = $this->files->contents($template->path, $template->disk);

            // The cast that used to be on the line above turned a template whose
            // bytes are gone into an empty string, which then passed the quota
            // check below and produced a real, named, zero-byte document in
            // someone's library. Somebody is waiting on this request, so they are
            // told instead; the log names the file, because an abort is not
            // reported anywhere and a disk across a network will fail this way.
            if ($contents === null) {
                Log::warning('Document template file is missing from storage', [
                    'feature' => 'documents.office.create',
                    'workspace_id' => $workspaceId,
                    'template_id' => $template->id,
                    'path' => $template->path,
                ]);

                abort(404, __('This template is missing its file. Upload the template again.'));
            }

            // The client's own details, filled in where the template asked for
            // them. Same tokens as the campaigns, filled by the same service.
            if ($contact) {
                $contents = $this->filler->fill($extension, $contents, function (string $token) use ($contact): ?string {
                    $rendered = $this->personalizer->renderText('{{'.$token.'}}', $contact);

                    // Unchanged means the token means nothing here, and the
                    // template should show that rather than lose the line.
                    return $rendered === '{{'.$token.'}}' ? null : $rendered;
                });
            }
        } else {
            $extension = ($data['kind'] ?? 'word') === 'cell' ? 'xlsx' : 'docx';
            $contents = (string) file_get_contents(__DIR__.'/../../resources/blank/blank.'.$extension);
        }

        if (! $this->quota->fits($workspaceId, strlen($contents))) {
            return back()->with('error', __('No room left for :name. Free up space or move to a larger plan.', [
                'name' => $data['name'],
            ]));
        }

        $mime = $template->mime ?? ($extension === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $entry = $this->files->put('documents', $data['name'].'.'.$extension, $mime, $contents);

        $document = Document::create([
            'workspace_id' => $workspaceId,
            'folder_id' => $this->ownedId(DocumentFolder::class, $workspaceId, $data['folder_id'] ?? null),
            'contact_id' => $contactId,
            'name' => $entry['name'],
            'path' => $entry['path'],
            'disk' => $entry['disk'],
            'mime' => $mime,
            'extension' => $extension,
            'size_bytes' => $entry['size'],
            'source' => 'generated',
            'created_by' => $request->user()->id,
        ]);

        $this->quota->forget($workspaceId);

        return redirect()->route('client.documents.office', $document->uuid);
    }

    /** @param  class-string<Model>  $model */
    private function ownedId(string $model, int $workspaceId, ?int $id): ?int
    {
        if (! $id) {
            return null;
        }

        return $model::where('workspace_id', $workspaceId)->whereKey($id)->value('id');
    }

    /** The editor page. */
    public function show(Request $request, Document $document): Response|RedirectResponse
    {
        abort_unless((int) $document->workspace_id === (int) $request->user()->workspace_id, 403);

        if (! $this->office->available()) {
            return redirect()
                ->route('client.documents.index')
                ->with('error', __('The document editor is not configured. See ONLYOFFICE_URL in the README.'));
        }

        if (! $this->office->opens($document->extension)) {
            return redirect()
                ->route('client.documents.index')
                ->with('error', __('This kind of file cannot be opened in the editor.'));
        }

        return Inertia::render('Documents/Office', [
            'document' => $document->only(['uuid', 'name', 'extension']),
            'serverUrl' => $this->office->url(),
            'config' => $this->office->config(
                $document,
                $request->user(),
                // A PDF opens to be read; the rest open to be worked on.
                editable: $this->office->edits($document->extension),
            ),
        ]);
    }

    /**
     * The file itself, for the Document Server.
     *
     * No session and no workspace check here on purpose — there is nobody to
     * check. What stands in for it is the signature, which was minted only after
     * a real user's ownership was verified, expires in half an hour, and covers
     * this document and no other.
     */
    public function download(Request $request, string $document): HttpResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 403);

        $row = Document::where('uuid', $document)->firstOrFail();
        $contents = $this->files->contents($row->path, $row->disk);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The Document Server reporting on a document.
     *
     * Public, and it has to be: this request comes from a container, with no
     * cookie and no CSRF token. What guards it is the signature on the URL plus
     * the JWT the Document Server signs with the shared secret — either one
     * missing and nothing happens.
     *
     * The reply body matters: anything other than {"error": 0} shows the person
     * an error inside the editor, whatever we actually did.
     */
    public function callback(Request $request, string $document): JsonResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 403);

        $payload = $this->office->verify(
            $request->bearerToken() ?? (string) $request->input('token'),
        );

        abort_if($payload === null, 403);

        $status = (int) ($payload['status'] ?? $request->integer('status'));
        $url = (string) ($payload['url'] ?? $request->input('url', ''));

        // 2 is "everyone has closed it, here is the result"; 6 is the same thing
        // mid-session, from the save button. The others — still editing, closed
        // unchanged, an error on their side — need nothing from us.
        if (in_array($status, [2, 6], true) && $url !== '') {
            $this->store($document, $url);
        }

        if (in_array($status, [3, 7], true)) {
            Log::error('ONLYOFFICE could not save a document', ['document' => $document, 'status' => $status]);
        }

        return response()->json(['error' => 0]);
    }

    /** Fetch what the editor produced and keep it as the new current version. */
    private function store(string $uuid, string $url): void
    {
        $row = Document::where('uuid', $uuid)->first();

        if (! $row) {
            return;
        }

        try {
            $response = Http::timeout(60)->get($this->office->reachable($url));
        } catch (\Throwable $e) {
            Log::error('Could not collect a saved document', ['document' => $uuid, 'error' => $e->getMessage()]);

            return;
        }

        if (! $response->successful()) {
            Log::error('Could not collect a saved document', ['document' => $uuid, 'status' => $response->status()]);

            return;
        }

        $contents = $response->body();
        $growth = max(0, strlen($contents) - (int) $row->size_bytes);

        // Only the difference has to fit: the copy being replaced is already
        // counted, and refusing an edit for space it already occupies would be
        // a strange way to lose someone's work.
        if ($growth > 0 && ! $this->quota->fits((int) $row->workspace_id, $growth)) {
            Log::warning('A saved document did not fit the plan allowance', ['document' => $uuid]);

            return;
        }

        // The new bytes go down FIRST, and only then is the previous state
        // recorded as a version. The other order looks equivalent and is not:
        // put() now throws when the write fails, and with the version row
        // written first a failed save left a version pointing at the unchanged
        // current path — a duplicate of a file that never moved. The Document
        // Server retries any callback that does not answer {"error": 0}, so
        // that row multiplied once per retry.
        $entry = $this->files->put(self::DIRECTORY, $row->name, $row->mime, $contents);

        // What was there becomes a version. This is the whole reason editing in
        // place is safe: nothing overwrites without leaving the previous state
        // behind, and it is the same history an uploaded replacement writes.
        DocumentVersion::create([
            'document_id' => $row->id,
            'version' => (int) DocumentVersion::where('document_id', $row->id)->max('version') + 1,
            'name' => $row->name,
            'path' => $row->path,
            // $row has not been updated yet, so this is still the disk the
            // PREVIOUS bytes are on — which is the whole point of recording it.
            // This method is the reason the column had to exist before anything
            // moved: put() above has just written to whatever disk is current,
            // and if a migration is in flight that is not the disk the file
            // being superseded sits on. One row per disk, both readable, is a
            // state the schema could not describe until now.
            'disk' => $row->disk,
            'mime' => $row->mime,
            'size_bytes' => $row->size_bytes,
            'created_by' => $row->created_by,
        ]);

        $row->update(['path' => $entry['path'], 'disk' => $entry['disk'], 'size_bytes' => $entry['size']]);

        if ($this->extractor->supports($row->extension)) {
            ExtractDocumentTextJob::store($row->fresh(), $this->extractor->extract($row->extension, $contents));
        }

        $this->quota->forget((int) $row->workspace_id);
    }
}
