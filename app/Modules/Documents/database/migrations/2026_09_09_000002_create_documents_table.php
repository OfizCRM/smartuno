<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The document library.
     *
     * Designed once, for all four stages of the module: the columns the later
     * stages need are created here, nullable and unused, because adding a column
     * later means a migration on a table that already holds customers' files.
     * Each one says which stage first writes to it.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            // The key that appears in URLs. Numeric ids never leave the server.
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Where it sits, and who it is about. Both optional and independent:
            // the same contract is in "Contracts" and belongs to Clinica Nord,
            // and neither fact is a folder for the other.
            $table->foreignId('folder_id')->nullable()->constrained('document_folders')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 255);
            $table->string('path', 255);
            $table->string('mime', 191);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');

            // Where it came from — answers "how do I have this file", which is
            // asked exactly when it matters.
            $table->enum('source', ['upload', 'conversation', 'generated'])->default('upload');
            // Which message it was saved from. Written from stage 2.
            $table->unsignedBigInteger('source_message_id')->nullable();

            // Expiry and renewal. Written from stage 2. remind_days holds the
            // offsets asked for (e.g. [30, 7]); reminders_sent holds the ones
            // already sent, so a restart cannot send them twice.
            $table->date('expires_at')->nullable();
            $table->json('remind_days')->nullable();
            $table->json('reminders_sent')->nullable();

            // The chatbot knowledge base row, when the document feeds the bot.
            // Written from stage 3.
            $table->unsignedBigInteger('kb_document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The two ways the list is ever read.
            $table->index(['workspace_id', 'folder_id']);
            $table->index(['workspace_id', 'contact_id']);
            // The daily reminder sweep.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
