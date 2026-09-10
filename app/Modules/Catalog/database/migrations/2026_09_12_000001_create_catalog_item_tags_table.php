<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the firm wants the agent to know about one catalogue item: the short
     * words under "Pentru cine este" (green) and "Nu îl propune dacă" (red).
     *
     * One table and not two. On the screen they are two colour-coded lists, but
     * they are the same idea with the opposite sign — a condition under which
     * this item is or is not the right answer — and a second table would mean a
     * second model, a second index, a second validator and two code paths that
     * must be kept saying the same thing. `kind` carries the sign.
     *
     * Free text and not a tag dictionary: the value is that a plumber can type
     * "apartament la bloc" and a dentist "copii sub 12 ani" without anybody
     * maintaining a vocabulary. A list to curate is a CRM, not this.
     */
    public function up(): void
    {
        Schema::create('catalog_item_tags', function (Blueprint $table) {
            $table->id();

            // Carried directly rather than reached through catalog_items.
            // Tenancy in this codebase is manual, and a query that has to join
            // to find the workspace is a query someone will eventually write
            // without the join. Every read of this table filters on this column.
            $table->unsignedBigInteger('workspace_id');

            // Cascaded: a tag has no meaning without the item it describes, and
            // nothing has ever been shown to a customer from this row.
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();

            // 'fits'     — propose it when this is true  (green in the UI)
            // 'excludes' — do not propose it when this is true (red)
            $table->enum('kind', ['fits', 'excludes']);

            // Short on purpose. These are read at a glance as chips beside each
            // other; a sentence belongs in the item's description, and 64 is
            // wide enough for "apartament la bloc, fără boiler" and narrow
            // enough to keep the unique key below MySQL's limit.
            $table->string('label', 64);

            // Who put it there. 'ai' means the agent drafted it and a person
            // pressed confirm — nothing reaches this table unconfirmed — and it
            // is kept so a later stage can tell a firm which of its knowledge
            // it wrote itself.
            $table->enum('source', ['human', 'ai'])->default('human');

            $table->timestamps();

            // The panel on the item page: every tag of one item, in one query.
            $table->index(['workspace_id', 'catalog_item_id']);
            // "Which items are tagged like this" — what the agent asks in stage
            // 4, and what the autocomplete on the tag input reads.
            $table->index(['workspace_id', 'label']);

            // The same word twice on one item is a mistake, not a preference.
            // Enforced by the schema rather than by every writer remembering to
            // look first. The same label may appear once as 'fits' and once as
            // 'excludes' — nonsense, but the firm's nonsense to make.
            $table->unique(['catalog_item_id', 'kind', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_tags');
    }
};
