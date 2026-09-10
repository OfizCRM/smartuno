<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The offer a firm sends instead of writing one in Word: a numbered price
     * quote, built by hand from the catalogue, with its own totals and its own
     * PDF.
     *
     * Designed once for all five stages of the module: the columns the later
     * stages need are created here, nullable and unused, because adding a column
     * later means a migration on a table that already holds customers' offers.
     * Each one says which stage first writes to it.
     *
     * Money is integer minor units (bani) in every column. 24000 is 240,00 lei.
     * Never decimal — these are summed, and a float that drifts by a ban is a
     * total the customer can see is wrong.
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            // The key that appears in URLs. Numeric ids never leave the server.
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // The number the firm says on the phone — "OF-0142". Allocated from
            // offer_series under a row lock, so two people saving in the same
            // second cannot be handed the same one.
            $table->string('number');

            // Who it is for. Nullable because an offer is often typed before the
            // customer exists as a contact, and nullOnDelete because deleting a
            // contact must not destroy the record of what was quoted.
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Where it was created from and where it goes out. Written from
            // stage 2b (sending). Deliberately no foreign key on
            // conversation_id: the Inbox module is reached by query, never by
            // import, and a cascade from a purged conversation must not take the
            // offer with it.
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('channel', 32)->nullable();

            // draft | sent | accepted | refused | expired. A string and not an
            // enum: 'expired' is set by a sweep, and the states this list grows
            // are a product decision, not a schema migration.
            $table->string('status', 16)->default('draft');

            // Who built it. 'ai' is first written in stage 4; until then every
            // row is 'human', and the badge on the list depends on being able to
            // tell the two apart from the first offer ever saved.
            $table->enum('source', ['human', 'ai'])->default('human');

            $table->string('currency', 3)->default('RON');

            // The totals, as computed at save time. Held on the row and not
            // derived on read, because the list screen shows them 25 at a time
            // and because a sent offer's total must never move.
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            // The label the firm chose for the discount ("Discount fidelitate"),
            // shown on the PDF next to the amount.
            $table->string('discount_label', 64)->nullable();
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('shipping_cents')->default(0);

            // A SNAPSHOT of the seller's VAT regime, copied from client_profiles
            // when the offer is created. Not read live: a firm that registers for
            // VAT in March must not retroactively add VAT to what it quoted in
            // February. vat_status is the three-state gate ('none', 'standard',
            // 'on_collection') deciding the legal mention on the PDF; vat_rate is
            // stored beside it because 11% goods are ordinary, not an exception.
            $table->string('vat_status', 16)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->unsignedBigInteger('vat_cents')->default(0);

            $table->unsignedBigInteger('total_cents')->default(0);

            // "Ofertă valabilă până la". Defaults from offers.validity_days.
            $table->date('valid_until')->nullable();
            // Free text the firm adds, printed on the PDF.
            $table->text('notes')->nullable();
            // The accompanying message. Written from stage 2b (sending).
            $table->text('message_body')->nullable();

            // The documents row holding the rendered PDF, filed by the renderer.
            // Deliberately not a foreign key: documents are soft-deleted and then
            // hard-deleted by PurgeDeletedDocumentsCommand, and losing the file
            // must never cascade into losing the offer.
            $table->unsignedBigInteger('pdf_document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // When it went out, and who sent it. Written from stage 2b.
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            // What the customer answered, recorded by hand on the offer screen.
            $table->timestamp('decided_at')->nullable();
            $table->string('decision', 16)->nullable();

            // What the agent proposed and why it proposed it. Written from stage
            // 4; kept beside the offer so a human edit can be compared against
            // the draft it started from.
            $table->json('ai_json')->nullable();
            $table->text('ai_reason')->nullable();
            // Whether a person changed the AI draft before sending it. Stage 4
            // instrumentation: the one number that says whether the drafting is
            // worth keeping.
            $table->boolean('edited_before_send')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The number is the firm's own reference and must be unique within
            // the workspace — the allocator's lock is the first guard, this is
            // the one that holds when the lock is ever bypassed.
            $table->unique(['workspace_id', 'number']);
            // The status tabs, and the COUNT stage 4 puts in every Inertia
            // response. Not optional.
            $table->index(['workspace_id', 'status']);
            // The offers listed on a contact page.
            $table->index(['workspace_id', 'contact_id']);
            // The list as the screen reads it: newest first, and the date filter.
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
