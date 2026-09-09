<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\DocumentFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FolderController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $parentId = $this->ownedFolderId($workspaceId, $data['parent_id'] ?? null);
        $this->refuseDuplicate($workspaceId, $parentId, $data['name']);

        DocumentFolder::create([
            'workspace_id' => $workspaceId,
            'parent_id' => $parentId,
            'name' => $data['name'],
        ]);

        return back()->with('success', __('Folder created.'));
    }

    public function update(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $this->authorise($request, $folder);

        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $this->refuseDuplicate((int) $folder->workspace_id, $folder->parent_id, $data['name'], $folder->id);

        $folder->update(['name' => $data['name']]);

        return back()->with('success', __('Folder renamed.'));
    }

    /**
     * Remove a folder without removing what is in it.
     *
     * The documents move to the top level rather than disappearing with the
     * folder: someone tidying up their shelves does not mean to throw away the
     * contracts, and the database is set to null the reference for exactly this.
     * That rule only runs on a real delete, which is why folders are not soft
     * deleted — a tombstone would leave documents filed under a folder that is
     * no longer in the rail.
     */
    public function destroy(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $this->authorise($request, $folder);
        $folder->delete();

        return back()->with('success', __('Folder deleted. The documents in it were kept.'));
    }

    private function refuseDuplicate(int $workspaceId, ?int $parentId, string $name, ?int $ignoreId = null): void
    {
        $exists = DocumentFolder::where('workspace_id', $workspaceId)
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('A folder with this name is already here.'),
            ]);
        }
    }

    private function ownedFolderId(int $workspaceId, ?int $folderId): ?int
    {
        if (! $folderId) {
            return null;
        }

        return DocumentFolder::where('workspace_id', $workspaceId)->whereKey($folderId)->value('id');
    }

    private function authorise(Request $request, DocumentFolder $folder): void
    {
        abort_unless((int) $folder->workspace_id === (int) $request->user()->workspace_id, 403);
    }
}
