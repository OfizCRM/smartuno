<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which disk this version's bytes are on.
     *
     * The reason it cannot wait for the stage that actually moves files: a
     * version row is the ONE place where two different files, written at two
     * different moments, are recorded from a single request. OfficeController::
     * store() collects the saved bytes, writes them through PrivateFileStore,
     * then creates the DocumentVersion carrying the document's OLD path, then
     * overwrites documents.path with the new one. If the store's default disk
     * changed between the day the previous copy was written and this save — which
     * is the whole of a migration window — the version and the document are on
     * different disks, and the version's disk is the one nothing records.
     *
     * Note that the version row inherits the disk of the file it is describing,
     * not the disk that is current: it copies documents.disk from before the
     * update, exactly as it copies path, name, mime and size_bytes. Copying the
     * current disk instead would relabel an old file as living somewhere it does
     * not, which is worse than having no column at all — a wrong address reads as
     * an authoritative one.
     *
     * "Download, edit in Word, upload again" (VersionController) does the same
     * pair of writes, and lands here for the same reason.
     *
     * Same shape as documents.disk and as the media.disk precedent it follows:
     * varchar(32) NOT NULL DEFAULT 'local', so a forgotten write site is a
     * constraint error rather than a null that reads as "unknown". Nothing moves
     * in this stage; every row is written 'local'.
     */
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->string('disk', 32)->default('local')->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
