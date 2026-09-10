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
        $removed = 0;

        Document::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->with('versions')
            ->chunkById(100, function ($documents) use ($files, &$purged, &$removed) {
                foreach ($documents as $document) {
                    // The earlier files first, and only then the row.
                    //
                    // Every replacement — an upload, an edit saved in the editor, a
                    // re-rendered offer — leaves the previous file where it is and writes
                    // a document_versions row pointing at it. That row is the only record
                    // of the file's name. forceDelete() cascades those rows away, so
                    // deleting the document first is the one order in which the files
                    // become permanently unnameable: still stored, still paid for, and
                    // beyond the reach of anything in this application.
                    foreach ($document->versions as $version) {
                        // The pair, not the path on its own. Two rows can carry
                        // the same path on different disks — a document moved
                        // during a migration, and a version written before the
                        // move — and those are two separate files. Comparing
                        // paths alone would read them as one, skip the version's
                        // copy, and leave it stored and paid for forever, with
                        // the only record of its name about to be cascaded away.
                        $sameFile = $version->path === $document->path
                            && $version->disk === $document->disk;

                        if ($version->path !== '' && ! $sameFile) {
                            $files->delete($version->path, $version->disk);
                            $removed++;
                        }
                    }

                    $files->delete($document->path, $document->disk);
                    $removed++;

                    $document->forceDelete();
                    $purged++;
                }
            });

        $this->info("Purged {$purged} document(s), {$removed} file(s) removed.");

        return self::SUCCESS;
    }
}
