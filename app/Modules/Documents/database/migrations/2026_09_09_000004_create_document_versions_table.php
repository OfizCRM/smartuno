<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a document used to be.
     *
     * The current file stays on the document itself and a row is written here
     * only when it is replaced — simpler and faster than making every document a
     * pointer into this table. Without it, "download, edit in Word, upload
     * again" is a feature that silently destroys the previous contract.
     */
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 255);
            $table->string('path', 255);
            $table->string('mime', 191);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
