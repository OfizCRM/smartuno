<?php

namespace App\Modules\Documents\Console\Commands;

use App\Modules\Documents\Models\Document;
use App\Modules\Shared\Services\PrivateFileStore;
use Illuminate\Console\Command;

/**
 * Empties the bin.
 *
 * A deleted document stops counting against the allowance straight away, but its
 * file stays on the disk so it can be recovered — which means without this the
 * disk grows for ever while the number on the screen says otherwise. Thirty days
 * is long enough to notice a mistake and short enough that the two do not drift
 * far apart.
 */
class PurgeDeletedDocumentsCommand extends Command
{
    protected $signature = 'documents:purge {--days=30 : How long a deleted document is kept}';

    protected $description = 'Delete the files of documents that were removed long enough ago';

    public function handle(PrivateFileStore $files): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $purged = 0;

        Document::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->chunkById(100, function ($documents) use ($files, &$purged) {
                foreach ($documents as $document) {
                    $files->delete($document->path);
                    $document->forceDelete();
                    $purged++;
                }
            });

        $this->info("Purged {$purged} document(s).");

        return self::SUCCESS;
    }
}
