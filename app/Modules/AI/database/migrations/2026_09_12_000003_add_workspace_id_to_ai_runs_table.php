<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_runs records every LLM call — tokens, latency, model — but not who it was
 * for. The only way to attribute a run to a tenant today is to join through
 * chatbot_id to ai_chatbots.workspace_id (see AnalyticsService::aiCostByWorkspace),
 * which silently drops every run that has no chatbot: embeddings, and from this
 * stage on the catalogue's AI description helper. Per-tenant AI spend is
 * therefore unattributable exactly where it starts costing money.
 *
 * The column is nullable because historical rows genuinely have no workspace we
 * can prove, and because a NOT NULL column would make a future writer that
 * forgets it throw in the middle of an already-paid-for provider call. What can
 * be attributed is back-filled below through chatbot_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_runs') || Schema::hasColumn('ai_runs', 'workspace_id')) {
            return;
        }

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->nullable()->after('id');
            $table->index(['workspace_id', 'created_at'], 'ai_runs_workspace_created_idx');
        });

        // Back-fill the runs whose owner is already recoverable. Done one
        // chatbot at a time rather than as an UPDATE ... JOIN so it behaves the
        // same on every driver; ai_chatbots is a small table.
        if (Schema::hasTable('ai_chatbots')) {
            DB::table('ai_chatbots')
                ->select('id', 'workspace_id')
                ->orderBy('id')
                ->chunk(500, function ($chatbots) {
                    foreach ($chatbots as $chatbot) {
                        DB::table('ai_runs')
                            ->where('chatbot_id', $chatbot->id)
                            ->whereNull('workspace_id')
                            ->update(['workspace_id' => $chatbot->workspace_id]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_runs') || ! Schema::hasColumn('ai_runs', 'workspace_id')) {
            return;
        }

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex('ai_runs_workspace_created_idx');
            $table->dropColumn('workspace_id');
        });
    }
};
