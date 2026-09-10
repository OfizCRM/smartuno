<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One attempt by the agent to turn an inbound customer message into a draft
     * offer.
     *
     * WHY THIS TABLE EXISTS AT ALL. An offer row cannot record a draft that
     * failed, because when drafting fails there is no offer — no number, no
     * lines, no total. Without this table a failure is a log line nobody reads,
     * and the firm's only signal is that nothing ever appeared. The screen shows
     * the failed attempt with its Romanian reason precisely because a silent
     * miss is the failure mode that makes people stop trusting the agent.
     *
     * IT IS ALSO THE IDEMPOTENCY RECORD, and that is why the row is written by
     * the listener BEFORE the job is dispatched rather than by the job when it
     * starts. WhatsappDriver::processInbound catches every throwable from
     * message processing and only logs it (WhatsappDriver line 101), so a
     * listener that throws is invisible: the webhook is answered 200, Meta never
     * redelivers, and the draft is lost for good. The row is the evidence that
     * the message was seen, written on the synchronous path where the message
     * itself is written, before anything that can be dropped.
     *
     * Money lives on the offer, not here. This table records *whether* the agent
     * managed to build one, never what it was worth.
     */
    public function up(): void
    {
        Schema::create('offer_draft_attempts', function (Blueprint $table) {
            $table->id();

            // Tenancy is manual in this codebase — no global scope, no trait.
            // The column is carried here rather than reached through the
            // conversation, so that every read of this table can filter on it
            // without a join. A query that needs a join to find the workspace is
            // a query someone will eventually write without the join.
            $table->unsignedBigInteger('workspace_id');

            // Deliberately NOT foreign keys. The Inbox is reached by query, and
            // conversations are soft-deleted then purged; a cascade from a purged
            // thread must not silently erase the record that the agent was asked
            // for an offer and failed. The listener verifies both ids belong to
            // the workspace before writing the row.
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');

            // queued | drafted | failed | skipped.
            //   queued  – the row exists, the job has not finished
            //   drafted – offer_id is set and the offer is on the list
            //   failed  – nothing was produced; reason says why, in Romanian
            //   skipped – the situation changed while the job waited out its
            //             debounce (a person took the thread, the toggle was
            //             switched off, the subscription lapsed)
            // A string and not an enum: these states are a product decision, and
            // adding one must not be a migration on a live table.
            $table->string('status', 16)->default('queued');

            // A TRANSLATION KEY — 'offers.ai_fail_no_match' — never a provider
            // body and never a raw exception message. Two reasons, both hard
            // rules here: a provider error can carry the prompt back, which
            // carries the customer's own words and the firm's prices; and the
            // screen renders this straight to a Romanian user, so an English
            // stack trace is not a reason, it is an apology.
            $table->string('reason', 64)->nullable();

            // The offer that was produced, when one was. Not a foreign key for
            // the same reason as above: offers are soft-deleted, and a deleted
            // draft must leave its attempt standing.
            $table->unsignedBigInteger('offer_id')->nullable();

            // What the drafting cost, as the gateway reported it. Kept beside the
            // attempt and not only in ai_runs because the question a firm asks is
            // "what did the drafting cost me", which is this table summed, not a
            // join across every AI feature on the platform.
            $table->unsignedInteger('tokens')->default(0);

            $table->timestamps();

            // The count in the navigation badge and the "Ciorne AI" tab: how many
            // attempts of a status this workspace has.
            $table->index(['workspace_id', 'status']);
            // The attempts of one thread, newest first — what the offer screen
            // reads to show the request the draft came from, and what the
            // debounce falls back on.
            //
            // Named explicitly. Laravel's generated name for this one is
            // offer_draft_attempts_workspace_id_conversation_id_created_at_index,
            // which is 66 characters against MySQL's hard 64-character limit for
            // an identifier — the create fails outright with errno 1059, so the
            // table cannot be created at all on MySQL.
            $table->index(['workspace_id', 'conversation_id', 'created_at'], 'oda_workspace_conversation_created_index');

            // ONE ATTEMPT PER INBOUND MESSAGE, enforced by the database and not
            // by a cache lock. Meta redelivers a webhook it thinks was not
            // acknowledged, and two workers can process the redelivery at the
            // same instant; a cache check would let both through and the firm
            // would get two drafts, two offer numbers and two AI bills for one
            // customer question. The unique index is what makes the second one
            // impossible rather than unlikely.
            $table->unique('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_draft_attempts');
    }
};
