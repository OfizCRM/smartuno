<?php

namespace Tests\Feature\Offers;

use App\Modules\Offers\Models\OfferSeries;
use App\Modules\Offers\Services\OfferNumberAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The offer number.
 *
 * It is the one thing on the document a customer quotes back at you on the
 * phone, so it has to be stable, sequential and the firm's own — not a global
 * counter that tells every client how many offers every other client wrote.
 */
class OfferNumberTest extends TestCase
{
    use RefreshDatabase;

    private function allocator(): OfferNumberAllocator
    {
        return app(OfferNumberAllocator::class);
    }

    public function test_numbers_run_in_sequence_and_never_repeat(): void
    {
        $ws = $this->createWorkspaceContext()['workspace'];
        $allocator = $this->allocator();

        $numbers = collect(range(1, 5))->map(fn (): string => $allocator->next($ws->id));

        $year = now()->format('Y');
        $this->assertSame([
            "OF-{$year}-0001", "OF-{$year}-0002", "OF-{$year}-0003", "OF-{$year}-0004", "OF-{$year}-0005",
        ], $numbers->all());
        $this->assertSame(5, $numbers->unique()->count());
    }

    public function test_each_firm_has_its_own_counter(): void
    {
        $a = $this->createWorkspaceContext()['workspace'];
        $b = $this->createWorkspaceContext()['workspace'];
        $allocator = $this->allocator();

        $allocator->next($a->id);
        $allocator->next($a->id);

        $year = now()->format('Y');
        // B is not told that A is on its third.
        $this->assertSame("OF-{$year}-0001", $allocator->next($b->id));
        $this->assertSame("OF-{$year}-0003", $allocator->next($a->id));

        $this->assertSame(2, OfferSeries::count());
    }

    public function test_the_counter_starts_again_in_january(): void
    {
        $ws = $this->createWorkspaceContext()['workspace'];
        $allocator = $this->allocator();

        Carbon::setTestNow(Carbon::parse('2026-12-31 12:00:00', 'Europe/Bucharest'));
        $allocator->next($ws->id);
        $last = $allocator->next($ws->id);

        Carbon::setTestNow(Carbon::parse('2027-01-02 12:00:00', 'Europe/Bucharest'));
        $first = $allocator->next($ws->id);

        Carbon::setTestNow();

        $this->assertSame('OF-2026-0002', $last);
        // The counter restarts, and the YEAR is in the number — without it the
        // first offer of January collides with last January's on the
        // unique(workspace_id, number) index and the module stops issuing
        // offers, permanently and with a 500.
        $this->assertSame('OF-2027-0001', $first);
        $this->assertSame(2027, (int) OfferSeries::where('workspace_id', $ws->id)->value('year'));
    }

    public function test_the_row_belongs_to_the_workspace_and_is_created_once(): void
    {
        $ws = $this->createWorkspaceContext()['workspace'];
        $allocator = $this->allocator();

        $allocator->next($ws->id);
        $allocator->next($ws->id);

        $this->assertSame(1, OfferSeries::where('workspace_id', $ws->id)->count());
        $this->assertSame(2, (int) OfferSeries::where('workspace_id', $ws->id)->value('last_number'));
    }
}
