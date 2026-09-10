<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One catalogue item pointing at another.
     *
     * Two relationships, one table, because they are the same edge with a
     * different meaning:
     *
     *   'bundle_component' — the owner is a bundle, and the other item is one of
     *                        the things it is made of. `quantity` says how many.
     *   'cross_sell'       — "merge bine împreună cu". A suggestion, nothing more.
     *
     * A bundle is therefore not a fourth kind of thing: it is a catalog_items row
     * with type = 'bundle' whose components are its bundle_component links. The
     * item page, the search, the price and the offer line already work for it.
     *
     * NOTHING HERE ENFORCES TENANCY. Both foreign keys point at catalog_items
     * without looking at workspace_id, so the database will happily store a link
     * to another firm's product if a controller lets one through. That is the
     * natural place for a leak in this feature — a related_item_id arrives from
     * the browser — so every writer must resolve the other item inside this
     * workspace and REFUSE what it cannot find, rather than silently dropping it.
     */
    public function up(): void
    {
        Schema::create('catalog_item_links', function (Blueprint $table) {
            $table->id();

            // Carried directly rather than reached through catalog_items, for
            // the same reason as everywhere else in this module: a query that
            // has to join to find the workspace is a query someone will
            // eventually write without the join.
            $table->unsignedBigInteger('workspace_id');

            // The owner: the bundle, or the item the suggestion hangs off.
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
            // The other one. The table has to be named — Laravel would guess
            // 'related_items' from the column.
            //
            // Cascaded and not nulled: a link whose far end is gone is not a
            // link, and a bundle silently keeping a row that points at a deleted
            // product would price itself wrong. Note that catalog_items is
            // soft-deleted, so the ordinary "delete an item" path never fires
            // this — it is the safety net for a hard delete.
            $table->foreignId('related_item_id')->constrained('catalog_items')->cascadeOnDelete();

            $table->enum('kind', ['bundle_component', 'cross_sell']);

            // How many of the component go into the bundle. Meaningless for a
            // cross_sell, which is why it defaults to 1 rather than being
            // nullable — a suggestion has no quantity to leave empty.
            //
            // Decimal and never a float, matching offer_items.quantity: 0,5 ore
            // of labour and 2,25 m of cable are ordinary components, and a
            // quantity that drifts through binary is a total the customer can
            // see is wrong.
            $table->decimal('quantity', 12, 3)->default(1);

            // The order the person arranged them in, and the order the
            // components are appended to an offer as lines. Explicit, because
            // "the order they were inserted" stops being true the first time a
            // component is removed and re-added.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // The two reads this table has: the composition panel and the
            // cross-sell chips, both "one item, one kind, in order".
            $table->index(['workspace_id', 'catalog_item_id', 'kind']);
            // The other direction — "which bundles contain this product" — which
            // is what a price change and a delete both have to ask.
            $table->index(['workspace_id', 'related_item_id']);

            // The same item twice in one bundle is a quantity, not a second row.
            $table->unique(['catalog_item_id', 'related_item_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_links');
    }
};
