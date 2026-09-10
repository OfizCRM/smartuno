<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which disk this template's bytes are on.
     *
     * A template is a stored file like any other — TemplateController writes it
     * through PrivateFileStore, reads it back to open the editor, and deletes it
     * with the row — so it carries the same half-address problem as documents:
     * a path with no disk beside it is resolved against whatever the application
     * is configured with right now, retroactively, for every row.
     *
     * It reaches the OfficeController case from the other side.
     * OfficeController::create() with a template_id reads the template's bytes
     * and writes a new document file in the same request; the two rows are then
     * on whichever disks each was written to, which during a migration window is
     * not the same disk. That read has to be told where to look, and this column
     * is the only thing that can tell it — and it already fails loudly when the
     * bytes are not where it looked (a null from contents() aborts 404 and logs
     * "Document template file is missing from storage"), so a wrong disk would
     * present as every template being gone.
     *
     * Rarer than documents and versions — a firm has a handful of templates, not
     * thousands — but a template that stops opening is every new contract and
     * every new offer blocked, so it is not the table to leave for later.
     *
     * Same shape as documents.disk and document_versions.disk, following the
     * media.disk precedent: varchar(32) NOT NULL DEFAULT 'local', so a forgotten
     * write site is a constraint error rather than a null that reads as
     * "unknown". Nothing moves in this stage; every row is written 'local'.
     */
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('disk', 32)->default('local')->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
