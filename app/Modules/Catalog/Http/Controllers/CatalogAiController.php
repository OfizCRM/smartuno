<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Models\CatalogItemTag;
use App\Modules\Catalog\Services\CatalogDescriber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Completează cu AI" — the two halves of it.
 *
 * describe() drafts and returns. apply() writes what the person ticked. They
 * are two requests on purpose: between them a human reads every sentence, and
 * nothing reaches the catalogue that they did not confirm.
 *
 * Both halves resolve the workspace themselves and refuse an id from any other
 * one. An item id is the whole payload of describe(), so the leak this guards
 * is not theoretical: without it, posting another firm's ids would return that
 * firm's product names in the proposals.
 */
class CatalogAiController extends Controller
{
    public function __construct(private readonly CatalogDescriber $describer) {}

    /**
     * Draft descriptions and tags for a batch. Writes nothing.
     *
     * Always JSON: the modal is showing a list to read and tick, not navigating
     * anywhere, so there are no Inertia props to refresh yet.
     */
    public function describe(Request $request): JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;

        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:'.CatalogDescriber::MAX_ITEMS],
            'item_ids.*' => ['integer', 'min:1'],
        ]);

        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_map('intval', $data['item_ids'])));

        $items = $this->items($workspaceId, $ids);

        try {
            $proposals = $this->describer->describe($workspaceId, $items);
        } catch (\RuntimeException $e) {
            // The message is already translated and already free of provider
            // detail — CatalogDescriber guarantees both. The provider's own
            // message travels as the previous exception, into the log only.
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['proposals' => $proposals]);
    }

    /**
     * Write the proposals the person confirmed.
     *
     * The payload arrives from the browser. That it started life in describe()
     * is not a reason to trust it back: every id is looked up in this workspace
     * again, and every label goes through the same cleaner the draft did.
     */
    public function apply(Request $request): JsonResponse|RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;

        $data = $request->validate([
            'proposals' => ['required', 'array', 'min:1', 'max:'.CatalogDescriber::MAX_ITEMS],
            'proposals.*.item_id' => ['required', 'integer', 'min:1'],
            'proposals.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'proposals.*.fits' => ['sometimes', 'nullable', 'array', 'max:'.CatalogDescriber::MAX_TAGS],
            'proposals.*.fits.*' => ['string', 'max:200'],
            'proposals.*.excludes' => ['sometimes', 'nullable', 'array', 'max:'.CatalogDescriber::MAX_TAGS],
            'proposals.*.excludes.*' => ['string', 'max:200'],
        ]);

        /** @var array<int, array<string, mixed>> $byItem */
        $byItem = [];
        foreach ($data['proposals'] as $proposal) {
            // One entry per item. A payload repeating an id is a bug upstream,
            // not a request to write it twice.
            $byItem[(int) $proposal['item_id']] ??= $proposal;
        }

        $items = $this->items($workspaceId, array_keys($byItem));

        $written = 0;

        DB::transaction(function () use ($items, $byItem, $workspaceId, &$written): void {
            foreach ($items as $item) {
                $proposal = $byItem[(int) $item->id];

                $clean = $this->describer->clean(
                    $item,
                    $proposal['description'] ?? null,
                    $proposal['fits'] ?? null,
                    $proposal['excludes'] ?? null,
                );

                $this->writeDescription($item, $clean['description']);
                $this->writeTags($workspaceId, $item, CatalogItemTag::KIND_FITS, $clean['fits']);
                $this->writeTags($workspaceId, $item, CatalogItemTag::KIND_EXCLUDES, $clean['excludes']);

                $written++;
            }
        });

        $message = trans_choice(':count product(s) completed.', $written);

        // Inertia asks for HTML and gets a redirect, so the list behind the
        // modal reloads with a fresh needs_help count. A plain fetch asks for
        // JSON and gets JSON. Either front end works, neither has to know.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['applied' => $written, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    /**
     * The items behind a list of ids, in this workspace.
     *
     * An id this workspace does not own is a 403, never a silently shorter
     * list: dropping it would turn a cross-tenant probe into a request that
     * looks like it worked, and would let somebody spend our tokens mapping
     * which ids exist.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, CatalogItem>
     */
    private function items(int $workspaceId, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        /** @var Collection<int, CatalogItem> $items */
        $items = CatalogItem::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();

        abort_unless($items->count() === count($ids), 403);

        return $items;
    }

    /**
     * Save the description, and record that a model wrote it.
     *
     * An empty one is left alone rather than written over: the person cleared
     * the box in the modal because they did not want that sentence, not because
     * they wanted the sentence already on the item deleted. Clearing a
     * description is done on the item's own page.
     */
    private function writeDescription(CatalogItem $item, string $description): void
    {
        if ($description === '') {
            return;
        }

        $item->update([
            'description' => $description,
            'description_source' => 'ai',
        ]);
    }

    /**
     * Add the confirmed tags, keeping the ones already there.
     *
     * firstOrCreate and not updateOrCreate: when a person already typed this
     * exact word by hand, the row stays theirs. Re-stamping it 'ai' would lose
     * the only record of who is responsible for the words on the screen.
     *
     * @param  list<string>  $labels
     */
    private function writeTags(int $workspaceId, CatalogItem $item, string $kind, array $labels): void
    {
        foreach ($labels as $label) {
            CatalogItemTag::query()->firstOrCreate(
                [
                    // workspace_id is in the lookup and not only in the insert:
                    // there is no global scope in this application, so a query
                    // without it is a query across every firm's data — even one
                    // whose other clause happens to make that impossible today.
                    'workspace_id' => $workspaceId,
                    'catalog_item_id' => (int) $item->id,
                    'kind' => $kind,
                    'label' => $label,
                ],
                ['source' => 'ai'],
            );
        }
    }
}
