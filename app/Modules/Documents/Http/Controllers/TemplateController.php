<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Shared\Services\PrivateFileStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Templates, made out of documents that already exist.
 *
 * There is no separate place to write one: a template is always something
 * somebody has just finished writing, and asking them to retype it elsewhere is
 * how template libraries end up empty.
 */
class TemplateController extends Controller
{
    private const DIRECTORY = 'document-templates';

    public function __construct(private readonly PrivateFileStore $files) {}

    /** Keep a copy of this document as a template. */
    public function store(Request $request, Document $document): RedirectResponse
    {
        abort_unless((int) $document->workspace_id === (int) $request->user()->workspace_id, 403);

        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);

        $contents = $this->files->contents($document->path);
        abort_if($contents === null, 404);

        // A copy, so editing the document later does not quietly change every
        // template made from it.
        $entry = $this->files->put(self::DIRECTORY, $document->name, $document->mime, $contents);

        DocumentTemplate::create([
            'workspace_id' => $document->workspace_id,
            'name' => $data['name'],
            'path' => $entry['path'],
            'mime' => $document->mime,
            'extension' => $document->extension,
            'size_bytes' => $entry['size'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', __('Saved as a template.'));
    }

    public function destroy(Request $request, DocumentTemplate $template): RedirectResponse
    {
        abort_unless((int) $template->workspace_id === (int) $request->user()->workspace_id, 403);

        $this->files->delete($template->path);
        $template->delete();

        return back()->with('success', __('Template deleted.'));
    }
}
