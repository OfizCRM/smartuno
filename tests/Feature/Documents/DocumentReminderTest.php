<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Models\Document;
use App\Notifications\DocumentExpiringNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Warnings before a document runs out.
 *
 * The contract that renews itself tacitly in thirty days, with nobody watching,
 * is the problem this module exists to solve — so what matters is that the
 * warning goes out once, goes out even if the sweep was down for a week, and
 * comes back if the date moves.
 */
class DocumentReminderTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function document(array $attrs = []): Document
    {
        return Document::create(array_merge([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'contract.pdf',
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'created_by' => $this->ctx['user']->id,
        ], $attrs));
    }

    public function test_nothing_goes_out_before_the_window_opens(): void
    {
        $this->document(['expires_at' => now()->addDays(60), 'remind_days' => [30, 7]]);

        $this->artisan('documents:remind')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_warning_reaches_the_people_who_run_the_firm(): void
    {
        $this->document(['expires_at' => now()->addDays(30), 'remind_days' => [30, 7]]);

        $this->artisan('documents:remind')->assertSuccessful();

        Notification::assertSentTo($this->ctx['user'], DocumentExpiringNotification::class);
    }

    public function test_the_same_warning_is_not_sent_twice(): void
    {
        $document = $this->document(['expires_at' => now()->addDays(30), 'remind_days' => [30, 7]]);

        $this->artisan('documents:remind');
        $this->artisan('documents:remind');

        Notification::assertSentToTimes($this->ctx['user'], DocumentExpiringNotification::class, 1);
        $this->assertSame([30], $document->fresh()->reminders_sent);
    }

    public function test_a_sweep_that_was_down_still_warns_and_only_once(): void
    {
        // Both offsets came due while nothing was running.
        $document = $this->document(['expires_at' => now()->addDays(3), 'remind_days' => [30, 7]]);

        $this->artisan('documents:remind')->assertSuccessful();

        // One message, for the nearest offset — not a stack about the same file.
        Notification::assertSentToTimes($this->ctx['user'], DocumentExpiringNotification::class, 1);
        Notification::assertSentTo($this->ctx['user'], DocumentExpiringNotification::class,
            fn (DocumentExpiringNotification $n) => $n->daysBefore === 7);
        // And both are marked, so neither fires again tomorrow.
        $this->assertEqualsCanonicalizing([30, 7], $document->fresh()->reminders_sent);
    }

    public function test_moving_the_date_makes_the_warnings_due_again(): void
    {
        $document = $this->document(['expires_at' => now()->addDays(30), 'remind_days' => [30]]);
        $this->artisan('documents:remind');
        $this->assertSame([30], $document->fresh()->reminders_sent);

        // A contract pushed out by a year has not been announced for its new date.
        $this->actingAs($this->ctx['user'])->patch(route('client.documents.update', $document->uuid), [
            'expires_at' => now()->addYear()->toDateString(),
            'remind_days' => [30],
        ]);

        $this->assertSame([], $document->fresh()->reminders_sent);
    }

    public function test_a_document_with_no_date_is_never_chased(): void
    {
        $this->document(['remind_days' => [30]]);
        $this->document(['expires_at' => now()->addDay()]);

        $this->artisan('documents:remind')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_an_expired_document_is_still_worth_saying_something_about(): void
    {
        $this->document(['expires_at' => now()->subDays(2), 'remind_days' => [7]]);

        $this->artisan('documents:remind')->assertSuccessful();

        Notification::assertSentTo($this->ctx['user'], DocumentExpiringNotification::class);
    }
}
