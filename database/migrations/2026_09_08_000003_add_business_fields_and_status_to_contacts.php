<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a contact the things a Romanian firm actually writes down about a
 * customer, and a lifecycle state.
 *
 * These are real columns rather than keys inside contacts.custom_fields, which
 * was the only place they could otherwise go. That JSON column already holds
 * instagram_psid and messenger_psid — the identities those two drivers match an
 * incoming message against — and ContactController::update replaces the whole
 * blob, so a form writing there would fork every repeat sender into a duplicate
 * contact, silently, days later.
 *
 * `status` drives the badge on the list and the filter chips above it. New
 * contacts start as 'lead': someone you have just met is a lead, and if they
 * are more than that the person who knows says so. 'inactive' is never guessed
 * from silence — a customer who has not written in four months may simply not
 * have needed anything.
 */
return new class extends Migration
{
    /** The states a contact can be in. Mirrored by Contact::STATUSES. */
    private const STATUSES = ['lead', 'negotiating', 'client', 'inactive'];

    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('company', 191)->nullable()->after('last_name');
            $table->string('job_title', 128)->nullable()->after('company');
            $table->string('tax_id', 32)->nullable()->after('job_title');
            $table->string('address', 255)->nullable()->after('tax_id');
            $table->string('city', 128)->nullable()->after('address');
            $table->date('birthday')->nullable()->after('city');
            $table->enum('status', self::STATUSES)->default('lead')->after('birthday');

            // The list filters and counts by it, always inside one workspace.
            $table->index(['workspace_id', 'status']);
        });

        // One-time backfill. Everything is 'lead' by the column default; a contact
        // the shop has actually sold to is a client, and that is the one thing the
        // data can say for certain. Anyone who disagrees changes it on the record.
        if (Schema::hasTable('ecommerce_orders')) {
            DB::table('contacts')
                ->whereIn('id', fn ($q) => $q->select('contact_id')
                    ->from('ecommerce_orders')
                    ->whereNotNull('contact_id'))
                ->update(['status' => 'client']);
        }
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'status']);
            $table->dropColumn(['company', 'job_title', 'tax_id', 'address', 'city', 'birthday', 'status']);
        });
    }
};
