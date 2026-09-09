<?php

namespace App\Modules\Documents\Services;

use App\Models\Workspace;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * How much room a workspace has left, and how much it is using.
 *
 * Measured, not metered. UsageMeter is a monthly counter that nothing increments
 * today, and space is not a monthly quantity anyway — it is a current value that
 * goes down when you delete something. So the figure is computed from the rows
 * themselves and cannot drift out of step with reality.
 *
 * Both halves count, because both live on the same disk: a tenant who could fill
 * it with email attachments while the library said 2% would have a limit that
 * does not limit.
 */
class DocumentQuota
{
    /** A workspace with no plan at all. Enough to try the product, not to live in it. */
    private const FALLBACK_MB = 1024;

    /**
     * Attachment bytes are a scan across the workspace's messages, so the answer
     * is held briefly. Ten minutes of drift on a storage bar is invisible; the
     * same scan on every page load would not be.
     */
    private const ATTACHMENT_CACHE_SECONDS = 600;

    /** Bytes allowed, or null for unlimited. */
    public function limitBytes(int $workspaceId): ?int
    {
        $workspace = Workspace::with('client')->find($workspaceId);
        $plan = $workspace?->client?->activePlan();

        // The admin plan form stores this in megabytes, labelled "Storage (MB)".
        $mb = $plan?->limitValue('storage');

        if ($plan && $mb === null) {
            // An explicit null on a plan means unlimited, which is how the
            // Business plan is set up.
            return null;
        }

        return (int) ($mb ?? self::FALLBACK_MB) * 1024 * 1024;
    }

    /**
     * @return array{documents: int, attachments: int, total: int}
     */
    public function usage(int $workspaceId): array
    {
        $documents = (int) Document::where('workspace_id', $workspaceId)->sum('size_bytes');
        $attachments = $this->attachmentBytes($workspaceId);

        return [
            'documents' => $documents,
            'attachments' => $attachments,
            'total' => $documents + $attachments,
        ];
    }

    /** Whether one more file of this size fits. */
    public function fits(int $workspaceId, int $bytes): bool
    {
        $limit = $this->limitBytes($workspaceId);

        if ($limit === null) {
            return true;
        }

        return ($this->usage($workspaceId)['total'] + $bytes) <= $limit;
    }

    /** Called after a write so the bar does not lag behind the file just added. */
    public function forget(int $workspaceId): void
    {
        Cache::forget($this->cacheKey($workspaceId));
    }

    private function attachmentBytes(int $workspaceId): int
    {
        return Cache::remember($this->cacheKey($workspaceId), self::ATTACHMENT_CACHE_SECONDS, function () use ($workspaceId) {
            try {
                // JSON_TABLE expands the attachments array so the sizes can be
                // summed in the database rather than by loading every message.
                // The column is not called `stored`: that is a reserved word in
                // MySQL and the statement fails to parse with it.
                $row = DB::selectOne(
                    'SELECT COALESCE(SUM(jt.size), 0) AS total
                       FROM messages m
                       JOIN conversations c ON c.id = m.conversation_id
                       JOIN JSON_TABLE(
                            m.payload->"$.attachments", "$[*]"
                            COLUMNS (size BIGINT PATH "$.size", was_stored BOOL PATH "$.stored")
                       ) jt
                      WHERE c.workspace_id = ? AND jt.was_stored = 1',
                    [$workspaceId]
                );

                return (int) ($row->total ?? 0);
            } catch (\Throwable $e) {
                // JSON_TABLE needs MySQL 8. On anything older the library still
                // has to work — it just stops counting the other half, which is
                // better than a screen that will not load.
                Log::warning('Could not measure attachment storage', ['error' => $e->getMessage()]);

                return 0;
            }
        });
    }

    private function cacheKey(int $workspaceId): string
    {
        return "documents:attachment-bytes:{$workspaceId}";
    }
}
