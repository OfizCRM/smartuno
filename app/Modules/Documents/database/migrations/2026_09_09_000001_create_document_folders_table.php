<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a document sits, as a plain tree the tenant builds themselves.
     *
     * No folders are created at install: a dental clinic and a plumber do not
     * keep the same dossiers, and five empty folders on day one are five things
     * to delete. The screen offers to create the usual set on request instead.
     *
     * Deleted for real, not softly — a folder is a label, and the documents it
     * held survive it. Keeping tombstones would mean the cascade below and the
     * null-on-delete on documents.folder_id never fire, which is a document
     * still filed under a folder nobody can see.
     */
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Null is a top-level folder. Deleting a parent takes its children
            // with it, which is what a person expects of a folder.
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();

            $table->index(['workspace_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_folders');
    }
};
