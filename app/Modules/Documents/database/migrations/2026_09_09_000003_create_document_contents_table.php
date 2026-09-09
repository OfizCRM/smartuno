<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The readable text of a document, so a contract can be found by a clause
     * and not only by its filename.
     *
     * Its own table rather than a column: the list query reads every document in
     * a folder, and a mediumtext on that row would be dragged along every time
     * for nothing.
     */
    public function up(): void
    {
        Schema::create('document_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->mediumText('text');
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_contents');
    }
};
