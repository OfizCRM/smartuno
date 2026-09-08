<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a thread hides it from the inbox for good, but keeps the row.
     *
     * A mailbox fills with things nobody needs — receipts, notifications, the
     * hosting company's own mail — and without a way to clear them the list
     * becomes unusable within weeks. Soft rather than hard because an inbox row
     * deleted by mistake is a customer's order, and because de-duplication has
     * to keep seeing the messages that were filed under a deleted thread or the
     * next poll files them all over again.
     *
     * No index: `deleted_at IS NULL` is a low-cardinality residual filter that
     * runs after the existing workspace_id index has already done the work, and
     * an index would cost every write for nothing.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
