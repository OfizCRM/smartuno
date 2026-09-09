<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A contract or offer the firm writes once and reuses.
     *
     * A real Office file, not markup: it is written in the same editor as
     * everything else, and a new document from it is a copy with the client's
     * details filled into the `{{contact.*}}` placeholders — the same tokens the
     * campaigns already use, so a person who has written one mail merge knows
     * this one too.
     */
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('path', 255);
            $table->string('mime', 191);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
