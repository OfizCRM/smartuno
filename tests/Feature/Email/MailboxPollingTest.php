<?php

namespace Tests\Feature\Email;

use App\Modules\Email\Jobs\PollMailboxJob;
use App\Modules\Email\Services\InboundMailParser;
use App\Modules\Email\Services\MailboxIngestor;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsClientEntitlementStates;
use Tests\TestCase;

/**
 * Which mailboxes get read, and when.
 *
 * The polling itself needs a mail server, so what is pinned here is everything
 * around it: the schedule decision, the entitlement gate and the host guard —
 * the three that decide whether a socket is opened at all.
 */
class MailboxPollingTest extends TestCase
{
    use BuildsClientEntitlementStates, RefreshDatabase;

    private function mailbox(int $workspaceId, array $meta = [], string $status = 'active'): ChannelAccount
    {
        $account = new ChannelAccount([
            'workspace_id' => $workspaceId,
            'channel' => 'email',
            'provider' => 'imap',
            'status' => $status,
            'display_name' => 'office@firma.ro',
        ]);
        $account->setAttribute('credentials', [
            'email' => 'office@firma.ro',
            'imap' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p'],
            'smtp' => ['host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p'],
        ]);
        $account->setAttribute('meta_json', array_merge(['poll_minutes' => 10, 'initial_days' => 2], $meta));
        $account->save();

        return $account;
    }

    public function test_a_mailbox_never_polled_is_due(): void
    {
        Queue::fake();
        $mailbox = $this->mailbox($this->createWorkspaceContext()['workspace']->id);

        $this->artisan('email:poll-mailboxes')->assertSuccessful();

        Queue::assertPushed(PollMailboxJob::class, fn ($job) => $job->mailboxId === $mailbox->id);
    }

    public function test_a_mailbox_read_a_moment_ago_is_left_alone(): void
    {
        Queue::fake();
        $this->mailbox($this->createWorkspaceContext()['workspace']->id, [
            'poll_minutes' => 10,
            'last_polled_at' => Carbon::now()->subMinutes(3)->toIso8601String(),
        ]);

        $this->artisan('email:poll-mailboxes')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_mailbox_past_its_interval_is_read_again(): void
    {
        Queue::fake();
        $this->mailbox($this->createWorkspaceContext()['workspace']->id, [
            'poll_minutes' => 10,
            'last_polled_at' => Carbon::now()->subMinutes(11)->toIso8601String(),
        ]);

        $this->artisan('email:poll-mailboxes')->assertSuccessful();

        Queue::assertPushed(PollMailboxJob::class);
    }

    public function test_force_ignores_the_interval(): void
    {
        Queue::fake();
        $this->mailbox($this->createWorkspaceContext()['workspace']->id, [
            'last_polled_at' => Carbon::now()->toIso8601String(),
        ]);

        $this->artisan('email:poll-mailboxes --force')->assertSuccessful();

        Queue::assertPushed(PollMailboxJob::class);
    }

    public function test_a_mailbox_in_error_is_still_retried_so_it_can_recover(): void
    {
        Queue::fake();
        $this->mailbox($this->createWorkspaceContext()['workspace']->id, [
            'last_polled_at' => Carbon::now()->subHour()->toIso8601String(),
        ], 'error');

        $this->artisan('email:poll-mailboxes')->assertSuccessful();

        Queue::assertPushed(PollMailboxJob::class);
    }

    public function test_an_unreadable_timestamp_does_not_freeze_a_mailbox_for_ever(): void
    {
        Queue::fake();
        $this->mailbox($this->createWorkspaceContext()['workspace']->id, ['last_polled_at' => 'nu-i o dată']);

        $this->artisan('email:poll-mailboxes')->assertSuccessful();

        Queue::assertPushed(PollMailboxJob::class);
    }

    public function test_a_lapsed_customer_mailbox_is_not_opened(): void
    {
        $ctx = $this->createWorkspaceContext();
        // Past the grace window the product allows after an expiry.
        $this->adminGrant($ctx['client'], Carbon::now()->subDays(30));

        $mailbox = $this->mailbox($ctx['workspace']->id);

        // Nothing in a scheduled job passes through middleware, so without this
        // the owner keeps paying to read the mail of an account that stopped.
        (new PollMailboxJob($mailbox->id))->handle(
            app(InboundMailParser::class),
            app(MailboxIngestor::class),
        );

        $mailbox->refresh();

        // Untouched: no connection attempt, so no error and no new timestamp.
        $this->assertNull($mailbox->getAttribute('meta_json')['last_polled_at'] ?? null);
        $this->assertSame('active', $mailbox->status);
    }

    public function test_an_internal_host_is_refused_at_the_socket_not_only_at_the_form(): void
    {
        $ctx = $this->createWorkspaceContext();
        $mailbox = $this->mailbox($ctx['workspace']->id);

        // Written straight to the row, the way a future caller might.
        $credentials = $mailbox->getAttribute('credentials');
        $credentials['imap']['host'] = '127.0.0.1';
        $mailbox->setAttribute('credentials', $credentials);
        $mailbox->save();

        (new PollMailboxJob($mailbox->id))->handle(
            app(InboundMailParser::class),
            app(MailboxIngestor::class),
        );

        $mailbox->refresh();

        $this->assertSame('error', $mailbox->status);
        $this->assertNotNull($mailbox->getAttribute('meta_json')['last_error']);
    }

    public function test_a_mailbox_that_no_longer_exists_is_a_no_op(): void
    {
        $mailbox = $this->mailbox($this->createWorkspaceContext()['workspace']->id);
        $id = $mailbox->id;
        $mailbox->delete();

        (new PollMailboxJob($id))->handle(
            app(InboundMailParser::class),
            app(MailboxIngestor::class),
        );

        $this->assertTrue(true);
    }
}
