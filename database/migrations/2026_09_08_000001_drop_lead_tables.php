<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the lead-prospecting tables along with the module itself.
 *
 * The feature scraped Google Maps for businesses and worked them through a
 * kanban board. It is gone for three reasons: it called the legacy Places API
 * Google closed to new projects, it stored Places content indefinitely against
 * the Maps Platform terms, and the businesses it found could never be messaged
 * on WhatsApp without opt-in — so the board's only honest use was a call list,
 * which is not what this product sells.
 *
 * Guarded with hasTable throughout: the migrations that created these tables
 * lived inside app/Modules/Leads and were deleted with it, so on a fresh
 * install the tables never exist and every drop below is a no-op.
 *
 * contacts.lead_id is deliberately left in place. It belongs to the Shared
 * module's own migration, no row has ever carried a value, and keeping it costs
 * nothing should a different version of this idea be built later.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Child first: lead_activities and leads carry foreign keys upward.
        foreach (['lead_activities', 'leads', 'lead_pipeline_stages', 'lead_scrape_jobs', 'lead_scoring_configs'] as $table) {
            Schema::dropIfExists($table);
        }

        // The integration row outlives the model constant that described it, and
        // would otherwise show on /admin/integrations as a provider with no label.
        if (Schema::hasTable('integration_configs')) {
            DB::table('integration_configs')->where('provider', 'google_places')->delete();
        }
    }

    /**
     * Not reversible: the tables were defined by migrations that no longer exist,
     * so there is nothing to recreate them from.
     */
    public function down(): void {}
};
