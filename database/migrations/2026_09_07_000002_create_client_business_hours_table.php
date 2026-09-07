<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening hours, stored as ONE ROW PER INTERVAL rather than one row per day.
 *
 * The obvious shape — seven rows, each with an opens_at and a closes_at — cannot
 * express the ordinary Romanian case: a dental clinic open 09:00-13:00 and again
 * 15:00-19:00, a restaurant that serves lunch and dinner. Storing intervals means
 * a split day is two rows, and the AI assistant answering "sunteti deschisi la 14?"
 * reads the same structure whether the day is split or not.
 *
 * There is deliberately NO unique constraint on (client_id, day_of_week): that
 * constraint is exactly what would forbid the second interval. The v1 UI shows a
 * single interval per day, but the schema already allows more, so adding split
 * hours later is a frontend change and not a migration on live tenant data.
 *
 * day_of_week is 1=Monday .. 7=Sunday (ISO-8601), matching Carbon's dayOfWeekIso —
 * NOT PHP's 0=Sunday, which is the easy mistake here.
 *
 * A closed day is a row with is_closed = true and null times, not a missing row:
 * "closed on Sunday" and "nobody has filled this in yet" must be distinguishable,
 * because only the first is safe to tell a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['client_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_business_hours');
    }
};
