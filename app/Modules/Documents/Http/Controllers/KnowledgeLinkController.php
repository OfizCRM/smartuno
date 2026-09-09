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
            // The private path. IndexDocumentJob knows to read `documents/` from
            // the private disk rather than the public one.
            'source_ref' => $document->path,
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
