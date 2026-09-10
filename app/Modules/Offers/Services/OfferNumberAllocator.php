<?php

namespace App\Modules\Offers\Services;

use App\Modules\Offers\Models\OfferSeries;
use App\Support\Romania;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The next offer number for a workspace: OF-0001, OF-0002, OF-0003.
 *
 * WHY THIS IS NOT THE AUTOINCREMENT ID. App\Services\Billing\InvoiceService
 * line 29 numbers an invoice as 'INV-' . str_pad($transaction->id, 6, '0'), and
 * that is exactly what an offer must not do:
 *
 *   - offers.id is ONE sequence shared by every tenant on the platform. The
 *     first offer a new customer writes would be OF-0000000000000000004821 —
 *     it tells them, and whoever they send it to, how many offers the whole
 *     product has ever produced. A firm's offer numbers are a firm's business.
 *   - An autoincrement is consumed by rows that were never committed: a failed
 *     insert, a rolled-back transaction, a deleted draft. The numbers arrive
 *     with holes in them, and an accountant reading a numbered series with gaps
 *     has to account for the missing ones.
 *   - It cannot restart in January. Romanian commercial documents are numbered
 *     per year, and an id has no idea what a year is.
 *
 * So the counter is a row of its own, one per workspace, and the increment is
 * done under a row lock. lockForUpdate() appears nowhere else in this codebase
 * — grep returns this file only — which is not evidence that it is unnecessary,
 * only that nothing else here has ever had to allocate a gapless sequence.
 *
 * The lock is what makes two people pressing "Creează oferta" in the same
 * second get OF-0142 and OF-0143 rather than OF-0142 twice. The unique index on
 * (workspace_id, number) is the second line of defence: if the lock is ever
 * bypassed the database refuses the duplicate rather than letting two different
 * offers go out under one number.
 */
class OfferNumberAllocator
{
    /** What the number starts with until a workspace has been given its own. */
    private const PREFIX = 'OF';

    /** OF-0142, not OF-142: a series that sorts as text has to be padded. */
    private const DIGITS = 4;

    /**
     * Take the next number in this workspace's series and hand it back.
     *
     * Allocating always consumes: the caller must be about to write the offer
     * row. Do not call this to preview what the next number would be.
     */
    public function next(int $workspaceId): string
    {
        // The year the SELLER is in, not the year UTC is in. config('app.timezone')
        // is UTC, so an offer written at half past one in the morning on the 1st
        // of January in Bucharest is still 31 December to the server — and would
        // be the last number of the old series instead of the first of the new.
        $year = CarbonImmutable::now(Romania::DEFAULT_TIMEZONE)->year;

        return DB::transaction(function () use ($workspaceId, $year): string {
            // The row has to exist before it can be locked, and two first
            // offers written at once would otherwise collide on the unique
            // workspace_id. insertOrIgnore makes the loser of that race a
            // no-op instead of a duplicate-key error; the select below then
            // finds the winner's row and locks it.
            OfferSeries::query()->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'prefix' => self::PREFIX,
                'year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /** @var OfferSeries $series */
            $series = OfferSeries::query()
                ->where('workspace_id', $workspaceId)
                ->lockForUpdate()
                ->firstOrFail();

            // A new year starts the series again at 1. The stored year is the
            // one the last number was issued in, so this fires on the first
            // offer of January and not once more until the next.
            if ((int) $series->year !== $year) {
                $series->year = $year;
                $series->last_number = 0;
            }

            $series->last_number = (int) $series->last_number + 1;
            $series->save();

            $prefix = trim((string) $series->prefix) !== '' ? trim((string) $series->prefix) : self::PREFIX;

            // The year is part of the number, not just of the counter. The
            // counter restarts every January, and offers.number carries a
            // unique(workspace_id, number) — so without the year, the first
            // offer of 2027 collides with 2026's OF-0001 and every workspace
            // that wrote an offer last year can never write another. It failed
            // with a 500 and did not self-heal: the nested transaction rolled
            // the counter back on each retry.
            return sprintf('%s-%d-%0'.self::DIGITS.'d', $prefix, $year, $series->last_number);
        });
    }
}
