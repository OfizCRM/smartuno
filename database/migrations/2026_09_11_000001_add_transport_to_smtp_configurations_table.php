<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a mail configuration sends, as opposed to where it points.
 *
 * Every row until now was SMTP, and on a server with outbound port 587 that is
 * the right and simplest answer. It stops being available the moment the app is
 * hosted somewhere that blocks outbound SMTP to prevent abuse — which is most
 * container platforms, Railway included, where a send does not fail but HANGS
 * for a minute and then times out.
 *
 * So the provider gains a second way in: the same account, reached over HTTPS on
 * 443, which nobody blocks. `host`, `port` and `encryption` stay on the table and
 * stay filled for SMTP rows; an API row simply does not read them.
 *
 * Defaulting to 'smtp' is what makes this invisible to every existing install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smtp_configurations', function (Blueprint $table) {
            $table->string('transport', 32)->default('smtp')->after('id');
        });

        // The four that describe an SMTP CONNECTION become nullable, because an
        // API row has no connection to describe. They were NOT NULL, which meant
        // an API configuration could not be saved at all — the form accepted it
        // and the insert failed on `host`.
        //
        // Nothing existing changes: every current row has values in all four,
        // and the controller still requires them whenever transport is 'smtp'.
        Schema::table('smtp_configurations', function (Blueprint $table) {
            $table->string('host', 255)->nullable()->change();
            $table->unsignedSmallInteger('port')->nullable()->default(587)->change();
            $table->string('username', 255)->nullable()->change();
            $table->string('encryption', 16)->nullable()->default('tls')->change();
        });
    }

    public function down(): void
    {
        Schema::table('smtp_configurations', function (Blueprint $table) {
            $table->dropColumn('transport');
        });

        // Deliberately not reverting the nullable changes: an API row would have
        // nulls in these columns and the change back would fail on it. Widening
        // a column is safe to leave widened.
    }
};
