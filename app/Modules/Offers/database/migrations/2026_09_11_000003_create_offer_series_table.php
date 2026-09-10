<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The counter behind "OF-0142": one row per workspace, holding the prefix
     * the firm chose, the year the sequence belongs to, and the last number
     * handed out.
     *
     * A table and not a MAX(number) over offers, because the number a customer
     * has been shown must never be reused, and MAX() over a soft-deleted row
     * that has already been quoted would hand it straight back out. The
     * allocator takes this row with lockForUpdate() inside a transaction, so two
     * people saving at the same second queue rather than collide.
     */
    public function up(): void
    {
        Schema::create('offer_series', function (Blueprint $table) {
            $table->id();
            // Unique: exactly one counter per workspace, enforced by the schema
            // rather than by the allocator remembering to look first.
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('prefix', 8)->default('OF');
            // The year the sequence belongs to. Held so the allocator can see
            // that the year has turned and restart at 1 rather than carrying
            // last year's count into January.
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_series');
    }
};
