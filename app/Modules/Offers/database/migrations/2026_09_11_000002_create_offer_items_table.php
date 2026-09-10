<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line on an offer.
     *
     * Every descriptive column here is a SNAPSHOT taken from the catalogue at
     * the moment the line was added, and is never refreshed. A firm that raises
     * a price, renames a product or deletes it entirely must not change what a
     * customer was already quoted — so the line owns its own name, unit and unit
     * price, and catalog_item_id is only a back-reference for "where did this
     * come from", nulled when the catalogue row goes away.
     *
     * Money is integer minor units (bani), as everywhere in this module.
     */
    public function up(): void
    {
        Schema::create('offer_items', function (Blueprint $table) {
            $table->id();

            // Carried directly rather than reached through the offer. Tenancy in
            // this codebase is manual, and a query that has to join to find the
            // workspace is a query someone will eventually write without the
            // join. Every read of this table filters on this column.
            $table->unsignedBigInteger('workspace_id');

            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            // Nulled, not cascaded: deleting a catalogue item must leave the
            // lines that quoted it standing, with their snapshot intact.
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();

            // The snapshot. See the note above.
            $table->string('name');
            $table->string('unit', 32)->default('buc');

            // Decimal and not an integer: 0,5 ore of labour and 2,25 m of cable
            // are ordinary lines. Three places because that is what a trade
            // quantity needs, and never a float — a quantity that drifts is a
            // total the customer can see is wrong.
            $table->decimal('quantity', 12, 3)->default(1);
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->unsignedBigInteger('line_total_cents')->default(0);

            // The order the person dragged the lines into. Explicit, because
            // "the order they were inserted" stops being true the first time a
            // line is deleted and re-added.
            $table->unsignedInteger('position')->default(0);

            // Whether a person added this line or the agent proposed it. 'ai' is
            // first written in stage 4.
            $table->enum('added_by', ['human', 'ai'])->default('human');

            // The bundle line this one is a component of. Written from stage 3,
            // when the catalogue learns to compose bundles. Deliberately not a
            // foreign key onto its own table: a self-referencing cascade on a
            // table this hot is a delete whose blast radius nobody can read.
            $table->unsignedBigInteger('bundle_parent_id')->nullable();

            $table->timestamps();

            // The lines of one offer, which is every read this table has.
            $table->index(['workspace_id', 'offer_id']);
            // "What has this catalogue item been quoted on" — the back-reference,
            // and what stage 5 counts.
            $table->index(['workspace_id', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_items');
    }
};
