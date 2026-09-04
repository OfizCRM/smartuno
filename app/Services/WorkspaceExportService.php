<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Generates a GDPR data export ZIP for a workspace.
 *
 * The archive contains CSVs for every major data type and a manifest.json
 * with generation timestamp and record counts.
 */
class WorkspaceExportService
{
    /** @var list<string> CSV files whose query failed, surfaced in the manifest. */
    private array $failed = [];

    /**
     * Build the export ZIP and return the relative storage path.
     * Stored under `exports/{workspaceId}/export_{timestamp}.zip`.
     */
    public function generate(User $user): string
    {
        $workspaceId = $user->current_workspace_id ?? $user->workspace_id;

        if (! $workspaceId) {
            throw new RuntimeException("User {$user->id} has no workspace; cannot build a data export.");
        }

        // audit_logs is scoped by client, not by workspace (see section 6).
        $clientId = DB::table('workspaces')->where('id', $workspaceId)->value('client_id');

        $this->failed = [];

        // 0700, not 0755: the archive holds personal data and sits in a shared temp dir.
        $tmpDir = sys_get_temp_dir().'/ws_export_'.$workspaceId.'_'.time();
        mkdir($tmpDir, 0700, true);

        $counts = [];

        // 1. Contacts
        $counts['contacts'] = $this->writeCsv($tmpDir.'/contacts.csv', function () use ($workspaceId) {
            return DB::table('contacts')
                ->where('workspace_id', $workspaceId)
                ->orderBy('id')
                ->lazyById(500);
        });

        // 2. Conversations + messages
        $counts['conversations'] = $this->writeCsv($tmpDir.'/conversations.csv', function () use ($workspaceId) {
            return DB::table('conversations')
                ->where('workspace_id', $workspaceId)
                ->orderBy('id')
                ->lazyById(500);
        });

        $counts['messages'] = $this->writeCsv($tmpDir.'/messages.csv', function () use ($workspaceId) {
            return DB::table('messages')
                ->whereIn('conversation_id', function ($q) use ($workspaceId) {
                    $q->select('id')->from('conversations')->where('workspace_id', $workspaceId);
                })
                ->orderBy('id')
                ->lazyById(500);
        });

        // 3. AI runs — ai_runs has no workspace_id. A run belongs to a workspace
        //    through its chatbot (the join Reports\ExportController::aiRuns uses) or,
        //    when chatbot_id is null, through its conversation.
        $counts['ai_runs'] = $this->writeCsv($tmpDir.'/ai_runs.csv', function () use ($workspaceId) {
            return DB::table('ai_runs')
                ->where(function ($w) use ($workspaceId) {
                    $w->whereIn('chatbot_id', function ($q) use ($workspaceId) {
                        $q->select('id')->from('ai_chatbots')->where('workspace_id', $workspaceId);
                    })->orWhereIn('conversation_id', function ($q) use ($workspaceId) {
                        $q->select('id')->from('conversations')->where('workspace_id', $workspaceId);
                    });
                })
                ->orderBy('id')
                ->lazyById(500);
        });

        // 4. Campaigns + recipients
        $counts['campaigns'] = $this->writeCsv($tmpDir.'/campaigns.csv', function () use ($workspaceId) {
            return DB::table('campaigns')
                ->where('workspace_id', $workspaceId)
                ->orderBy('id')
                ->lazyById(500);
        });

        $counts['campaign_recipients'] = $this->writeCsv($tmpDir.'/campaign_recipients.csv', function () use ($workspaceId) {
            return DB::table('campaign_recipients')
                ->whereIn('campaign_id', function ($q) use ($workspaceId) {
                    $q->select('id')->from('campaigns')->where('workspace_id', $workspaceId);
                })
                ->orderBy('id')
                ->lazyById(500);
        });

        // 5. Automations + runs
        $counts['automations'] = $this->writeCsv($tmpDir.'/automations.csv', function () use ($workspaceId) {
            return DB::table('automations')
                ->where('workspace_id', $workspaceId)
                ->orderBy('id')
                ->lazyById(500);
        });

        $counts['automation_runs'] = $this->writeCsv($tmpDir.'/automation_runs.csv', function () use ($workspaceId) {
            return DB::table('automation_runs')
                ->whereIn('automation_id', function ($q) use ($workspaceId) {
                    $q->select('id')->from('automations')->where('workspace_id', $workspaceId);
                })
                ->orderBy('id')
                ->lazyById(500);
        });

        // 6. Audit log — audit_logs has no workspace_id. Client rows carry client_id
        //    (AuditLogService::log), which is what Client\AuditLogController shows.
        //    Rows with a null client_id are platform-admin actions, not workspace data:
        //    never fall through to them, because where('client_id', null) compiles to
        //    "client_id is null" and would export every tenant's admin trail.
        $counts['audit_log'] = $this->writeCsv($tmpDir.'/audit_log.csv', function () use ($clientId) {
            if (! $clientId) {
                return [];
            }

            return DB::table('audit_logs')
                ->where('client_id', $clientId)
                ->orderBy('id')
                ->lazyById(500);
        });

        // 7. Social posts — the table is social_media_posts (Modules\Social\Models\SocialPost).
        $counts['social_posts'] = $this->writeCsv($tmpDir.'/social_posts.csv', function () use ($workspaceId) {
            return DB::table('social_media_posts')
                ->where('workspace_id', $workspaceId)
                ->orderBy('id')
                ->lazyById(500);
        });

        // 8. Segments and tags — the page's "what's included" list promises them.
        $counts['segments'] = $this->writeCsv($tmpDir.'/segments.csv', function () use ($workspaceId) {
            return DB::table('segments')
                ->where('workspace_id', $workspaceId)
                ->orderBy('id')
                ->lazyById(500);
        });

        $counts['contact_tags'] = $this->writeCsv($tmpDir.'/contact_tags.csv', function () use ($workspaceId) {
            return DB::table('contact_tags')
                ->where('workspace_id', $workspaceId)
                ->orderBy('id')
                ->lazyById(500);
        });

        // Manifest
        file_put_contents($tmpDir.'/manifest.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'workspace_id' => $workspaceId,
            'exported_by' => $user->email,
            'record_counts' => $counts,
            'failed_sections' => $this->failed,
        ], JSON_PRETTY_PRINT));

        // Zip everything
        $zipPath = $tmpDir.'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (glob($tmpDir.'/*') as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        // Store in local disk under exports/
        // Stream the archive: file_get_contents() would hold the whole ZIP in memory.
        // The local disk sets 'throw' => false, so a failed write returns false rather
        // than raising — check it, or the job mails a link to a file that isn't there.
        $storagePath = "exports/{$workspaceId}/export_".now()->format('Ymd_His').'.zip';
        $stream = fopen($zipPath, 'r');
        $stored = Storage::put($storagePath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($stored === false) {
            throw new RuntimeException("Failed to store workspace export at {$storagePath}.");
        }

        // Cleanup tmp
        array_map('unlink', glob($tmpDir.'/*'));
        @rmdir($tmpDir);
        @unlink($zipPath);

        return $storagePath;
    }

    /**
     * Write rows from a lazy query to a CSV file.
     * Returns the number of rows written (excluding the header).
     */
    private function writeCsv(string $path, callable $queryFactory): int
    {
        $handle = fopen($path, 'w');
        $count = 0;
        $headers = null;

        try {
            foreach ($queryFactory() as $row) {
                $rowArr = (array) $row;
                if ($headers === null) {
                    $headers = array_keys($rowArr);
                    fputcsv($handle, $headers);
                }
                fputcsv($handle, array_values($rowArr));
                $count++;
            }
        } catch (\Throwable $e) {
            // A broken section must not read as an empty one. Silently swallowing the
            // QueryException is what let three tables ship as "0 records" for months.
            $this->failed[] = basename($path);
            Log::error('Workspace export section failed', [
                'file' => basename($path),
                'exception' => $e->getMessage(),
            ]);

            fputcsv($handle, ['export_error']);
            fputcsv($handle, ['This section could not be exported. Please contact support.']);

            fclose($handle);

            return $count;
        }

        if ($headers === null) {
            fputcsv($handle, ['(empty)']);
        }

        fclose($handle);

        return $count;
    }
}
