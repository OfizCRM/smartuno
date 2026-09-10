<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which disk this document's bytes are on.
     *
     * `path` on its own is only half an address. It says where inside a disk the
     * file sits and never which disk that is, so the disk is answered by whatever
     * the code happens to be configured with at the moment it reads — one global
     * value, applied retroactively to every row. Change it and every file already
     * stored 404s at once, because the old rows are now being looked for in a
     * bucket they were never written to.
     *
     * This column is what makes a HALF-MIGRATED library expressible: some rows on
     * `local`, some on an object store, each one saying so itself, both readable
     * at the same time. Nothing moves today — every row is written 'local' and the
     * store still writes there — but a move is impossible to do safely until the
     * schema can describe the middle of it.
     *
     * The public side of this application settled the same question years of
     * commits ago and this follows it rather than inventing a second shape:
     * media.disk (varchar(32), default 'public'), clients.logo_disk, and the
     * app_logo_disk system setting are all a disk name stored next to the path it
     * belongs to.
     *
     * The case that forces the column to exist BEFORE a single byte moves is a
     * document edited during the migration window. OfficeController::store()
     * writes the new bytes first, then creates a DocumentVersion carrying the
     * document's OLD path, then overwrites documents.path with the new one. Run
     * that while files are being moved and the version row points at one disk
     * while the document row points at the other — a perfectly ordinary outcome
     * that today's schema cannot record, so the previous contract simply becomes
     * unreadable. document_versions gets the same column, in the migration beside
     * this one, for exactly that reason.
     *
     * NOT NULL DEFAULT 'local', not nullable: a write site that forgets the disk
     * should fail loudly on the constraint. A NULL would be indistinguishable
     * from "we do not know", and there is no way to find out afterwards which
     * disk a file was written to.
     *
     * 'local' is the literal fallback everywhere in this work, and it stays
     * literal. Thirteen test files call Storage::fake('local'), which swaps a
     * disk BY NAME; a differently named disk pointed at the same root would leave
     * every one of them writing to the real filesystem.
     *
     * No index. The column is never a search key — it is read off a row already
     * found by uuid or workspace_id, and with two distinct values it would not
     * narrow anything anyway.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('disk', 32)->default('local')->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
