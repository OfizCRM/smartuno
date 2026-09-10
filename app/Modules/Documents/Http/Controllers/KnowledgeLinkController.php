<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\Documents\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets a document answer for the chatbot.
 *
 * The whole link is one row in the knowledge base pointing at the file we
 * already hold — the price list the bot should quote from is the same one the
 * office sends to customers, and keeping two copies is how they drift apart.
 */
class KnowledgeLinkController extends Controller
{
    public function store(Request $request, Document $document): RedirectResponse
    {
        $this->authorise($request, $document);

        $data = $request->validate(['kb_id' => ['required', 'integer']]);

        $kb = AiKnowledgeBase::where('workspace_id', $request->user()->workspace_id)
            ->whereKey($data['kb_id'])
            ->first();

        abort_unless($kb !== null, 404);

        // Replacing an earlier link rather than stacking a second one.
        $this->unlink($document);

        $kbDocument = AiKbDocument::create([
            'kb_id' => $kb->id,
            'source_type' => 'file',
            // The document, not the file. This used to store $document->path,
            // and a bare path says nothing about which disk it is a path on —
            // so IndexDocumentJob guessed, by testing whether it began with
            // "documents/". That guess was wrong as soon as the private disk
            // stopped being the local one, and wrong again if a directory
            // prefix were configured; both times it read the wrong disk, found
            // nothing, and marked the row indexed with no text in it.
            //
            // A uuid identifies the row instead, and the row is what knows
            // where its file lives. Which also makes the read checkable: the
            // job can confirm the document and the knowledge base belong to the
            // same workspace, which a loose path never allowed.
            'source_ref' => IndexDocumentJob::DOCUMENT_REF_PREFIX.$document->uuid,
            'title' => $document->name,
            'status' => 'pending',
        ]);

        $document->update(['kb_document_id' => $kbDocument->id]);

        try {
            IndexDocumentJob::dispatch($kbDocument->id)->onQueue('ai');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('The bot will use this document.'));
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorise($request, $document);
        $this->unlink($document);

        return back()->with('success', __('The bot will no longer use this document.'));
    }

    private function unlink(Document $document): void
    {
        if ($document->kb_document_id) {
            // Deleting the row takes its chunks with it, so the bot stops
            // quoting a price list the office has withdrawn.
            AiKbDocument::whereKey($document->kb_document_id)->delete();
            $document->update(['kb_document_id' => null]);
        }
    }

    private function authorise(Request $request, Document $document): void
    {
        abort_unless((int) $document->workspace_id === (int) $request->user()->workspace_id, 403);
    }
}
