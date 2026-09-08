<?php

namespace Tests\Feature\Billing;

use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Models\ClientSubscription;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Broadcasting\Jobs\DispatchCampaignChunkJob;
use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Jobs\LaunchScheduledCampaignsJob;
use App\Modules\Broadcasting\Jobs\SendCampaignMessageJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Social\Jobs\DispatchScheduledPostsJob;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Whatsapp\Models\WhatsappAutoReply;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Notifications\ConversationHandoverNotification;
use App\Support\Entitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsClientEntitlementStates;
use Tests\TestCase;

/**
 * The other half of the subscription gate: the work the application does on its
 * own, where there is no HTTP request for EnforceSubscriptionAccess to see.
 *
 * Four background entry points stop for a read-only client — the chatbot
 * auto-reply, the automation triggers, the every-minute campaign launcher and
 * the every-minute social post dispatcher — and nothing else does.
 *
 * The two things this suite is really guarding:
 *
 *  1. A held item must be *held*, not consumed. A social post flipped to
 *     'publishing' and then skipped is stranded forever: nothing ever moves it
 *     back, so the customer pays and the post still never goes out. Every
 *     "blocked" test therefore asserts the row's state, not just an empty queue.
 *
 *  2. A paying customer must never be silenced. Grace, the second subscription
 *     table, a workspace with no client, an unknown workspace and a client with
 *     no subscription rows at all must every one of them keep working — asserted
 *     on a real dispatched job or a real outbound message row, never on the
 *     absence of something in an empty database.
 */
class BackgroundSubscriptionAccessTest extends TestCase
{
    use BuildsClientEntitlementStates, RefreshDatabase;

    /** The reply the (mocked) chatbot produces. Distinctive so the assertion cannot pass by accident. */
    private const BOT_REPLY = 'Buna ziua! Va putem programa joi la ora 10.';

    private const INBOUND_BODY = 'Buna ziua, mai aveti loc joi?';

    /** The reply a plain keyword rule sends — no chatbot, no LLM key, no cost. */
    private const KEYWORD_REPLY = 'Va raspundem in cel mai scurt timp.';

    /** What the node after a Wait sends, so the resumed-run assertions cannot pass by accident. */
    private const WAIT_NODE_REPLY = 'Va reamintim de programarea de maine.';

    /** What the node after an "Ask question" sends once the contact's answer arrives. */
    private const ASK_NODE_REPLY = 'Am notat, va multumim!';

    /**
     * Contains 'connect me to', one of AutoReplyListener's HANDOVER_PHRASES.
     * The phrases are English-only in the inherited code, so a Romanian sentence
     * would silently never match and the test would prove nothing.
     */
    private const HANDOVER_BODY = 'Buna ziua, please connect me to a real person';

    protected function setUp(): void
    {
        parent::setUp();

        Entitlement::forget();

        // Pin the grace window: SAAS_GRACE_DAYS in the developer's .env must not
        // be able to change what these tests mean.
        config(['saas.grace_days' => 7]);
    }

    /**
     * The two states that must behave identically to each other. Grace is here
     * because "grace is just active with a banner" is a product promise, and the
     * cheapest way to break it is to write `!== ACTIVE` instead of `=== READONLY`
     * in one of the four guards.
     *
     * @return array<string, array{0: string}>
     */
    public static function permittedStates(): array
    {
        return [
            'active' => [Entitlement::ACTIVE],
            'grace' => [Entitlement::GRACE],
        ];
    }

    // ─── 1. The chatbot auto-reply ───────────────────────────────────────────

    #[Test]
    public function a_readonly_client_gets_no_chatbot_reply(): void
    {
        Notification::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Bot Blocat SRL');

        // The runner is the thing that spends the LLM key — and CredentialResolver
        // falls back to the platform's key when the workspace has none, so this
        // is the owner's own OpenAI credit. Asserting on the outbound row alone
        // would still pass if we called the model and then threw the answer away.
        $this->mockSilentChatbotRunner();

        $conversation = $this->makeChatbotConversation($workspace->id);
        $inbound = $this->makeInboundMessage($conversation);

        MessageReceived::dispatch($inbound);

        $this->assertSame(0, $this->outboundCount($conversation), 'A read-only client must not answer on the tenant\'s behalf.');

        // The control: the customer's own message is still there, so the
        // assertion above is not passing against an empty conversation.
        $this->assertDatabaseHas('messages', ['id' => $inbound->id, 'direction' => 'in']);
    }

    #[Test]
    #[DataProvider('permittedStates')]
    public function an_entitled_client_still_gets_a_chatbot_reply(string $state): void
    {
        Notification::fake();

        [, , $workspace] = $this->makeClientInState($state, "Bot {$state} SRL");

        $this->mockChatbotRunner();
        $this->mockOutboundChannel();

        $conversation = $this->makeChatbotConversation($workspace->id);

        MessageReceived::dispatch($this->makeInboundMessage($conversation));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'sent_by' => 'bot',
            'body' => self::BOT_REPLY,
            'status' => 'sent',
        ]);
    }

    /**
     * THE TWO-TABLE TRAP. The admin grant lapsed a month ago; the firm has been
     * paying Stripe throughout. Reading only client_subscriptions would leave a
     * paying dental clinic's bot silently mute — the customer would never be
     * told, they would just stop getting answers.
     */
    #[Test]
    public function a_client_paying_through_the_other_table_still_gets_a_chatbot_reply(): void
    {
        Notification::fake();

        [$user, $client, $workspace] = $this->makeClient('Doua Tabele SRL');
        $this->adminGrant($client, now()->subDays(30), ClientSubscription::STATUS_EXPIRED);
        $this->gatewaySubscription($user, ['status' => 'active', 'ends_at' => now()->addDays(20)]);

        Entitlement::forget();
        $this->assertSame(Entitlement::ACTIVE, Entitlement::state($client->fresh()));
        Entitlement::forget();

        $this->mockChatbotRunner();
        $this->mockOutboundChannel();

        $conversation = $this->makeChatbotConversation($workspace->id);

        MessageReceived::dispatch($this->makeInboundMessage($conversation));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'body' => self::BOT_REPLY,
        ]);
    }

    /**
     * The guard sits above the keyword rules, not just above the chatbot, so a
     * read-only client's free-of-charge keyword and welcome replies stop too.
     * That is a deliberate scope decision — a keyword rule still puts an outbound
     * message on the tenant's channel, which is exactly what the HTTP gate
     * refuses — and it is pinned here so narrowing the guard to the paid LLM path
     * has to be an explicit choice with the owner rather than a silent drift.
     *
     * The one branch that is NOT gated is handover, which sends nothing outbound;
     * see a_readonly_client_still_hands_a_conversation_over_to_a_human below.
     */
    #[Test]
    public function a_readonly_client_sends_no_keyword_auto_reply_either(): void
    {
        Notification::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Cuvant Cheie SRL');

        // No chatbot at all: this path costs nothing in LLM credit, so if it
        // still fired, only the deliberate scope decision would be at stake.
        $conversation = $this->makeConversation($workspace->id);
        $this->makeKeywordAutoReply($workspace->id);

        MessageReceived::dispatch($this->makeInboundMessage($conversation));

        $this->assertSame(0, $this->outboundCount($conversation));
    }

    #[Test]
    public function an_entitled_client_still_sends_its_keyword_auto_reply(): void
    {
        Notification::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::ACTIVE, 'Cuvant Cheie Platit SRL');

        $this->mockOutboundChannel();

        $conversation = $this->makeConversation($workspace->id);
        $this->makeKeywordAutoReply($workspace->id);

        MessageReceived::dispatch($this->makeInboundMessage($conversation));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'sent_by' => 'bot',
            'body' => self::KEYWORD_REPLY,
        ]);
    }

    // ─── 1b. Handover to a human ─────────────────────────────────────────────

    /**
     * The one branch of AutoReplyListener that is deliberately NOT gated.
     *
     * Handover sends nothing outbound: it flags the conversation for a human and
     * raises an in-app notification whose via() is database + broadcast +
     * OneSignal (App\Notifications\ConversationHandoverNotification), none of
     * which reaches the customer or costs the owner anything. It is a read-side
     * inbox signal the tenant's own staff rely on, and the owner's decision was
     * explicit that inbound must still surface in the inbox. Gating it left a
     * patient asking for a person with a silent bot AND nobody flagged, which is
     * worse for the tenant than the block was ever meant to be.
     */
    #[Test]
    public function a_readonly_client_still_hands_a_conversation_over_to_a_human(): void
    {
        Notification::fake();

        [$user, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Predare Blocata SRL');

        // A chatbot is linked and a keyword rule matches, so both gated branches
        // would fire if the guard had not been narrowed. Neither may.
        $conversation = $this->makeChatbotConversation($workspace->id);
        $this->makeKeywordAutoReply($workspace->id);
        $this->mockSilentChatbotRunner();

        MessageReceived::dispatch($this->makeHandoverRequest($conversation));

        $fresh = $conversation->fresh();
        $this->assertSame('human', $fresh->assigned_to);
        $this->assertNotNull($fresh->handover_at);

        Notification::assertSentTo($user, ConversationHandoverNotification::class);

        // And still nothing on the tenant's own channel.
        $this->assertSame(0, $this->outboundCount($conversation));
    }

    // ─── 2. The automation triggers ──────────────────────────────────────────

    #[Test]
    public function a_readonly_client_fires_no_automation_on_an_inbound_message(): void
    {
        Queue::fake();
        Notification::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Automatizare Blocata SRL');

        $automation = $this->makeAutomation($workspace->id, 'message.received');
        $conversation = $this->makeConversation($workspace->id);
        $inbound = $this->makeInboundMessage($conversation);

        MessageReceived::dispatch($inbound);

        $this->assertDatabaseMissing('automation_runs', ['automation_id' => $automation->id]);
        Queue::assertNotPushed(ExecuteAutomationRunJob::class);

        // Control: the message that should have triggered it is stored.
        $this->assertDatabaseHas('messages', ['id' => $inbound->id, 'direction' => 'in']);
    }

    /**
     * contact.created is a separate handler with its own guard, and the guards
     * were added one per handler — so one of them can be forgotten without the
     * message.received test noticing.
     */
    #[Test]
    public function a_readonly_client_fires_no_automation_when_a_contact_is_created(): void
    {
        Queue::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Contact Blocat SRL');

        $automation = $this->makeAutomation($workspace->id, 'contact.created');

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        ContactCreated::dispatch($contact);

        $this->assertDatabaseMissing('automation_runs', ['automation_id' => $automation->id]);
        Queue::assertNotPushed(ExecuteAutomationRunJob::class);
    }

    #[Test]
    #[DataProvider('permittedStates')]
    public function an_entitled_client_still_fires_automations(string $state): void
    {
        Queue::fake();
        Notification::fake();

        [, , $workspace] = $this->makeClientInState($state, "Automatizare {$state} SRL");

        $automation = $this->makeAutomation($workspace->id, 'message.received');
        $conversation = $this->makeConversation($workspace->id);

        MessageReceived::dispatch($this->makeInboundMessage($conversation));

        $this->assertDatabaseHas('automation_runs', [
            'automation_id' => $automation->id,
            'contact_id' => $conversation->contact_id,
        ]);
        Queue::assertPushedOn('automation', ExecuteAutomationRunJob::class);
    }

    // ─── 2b. A run that wakes up after a Wait node ───────────────────────────

    /**
     * The six trigger guards only cover the moment a run *starts*. A run parked
     * on a Wait node wakes up days later, and everything expensive lives after
     * the Wait: an ai_reply or run_chatbot node reaches CredentialResolver,
     * which falls back to the platform LLM key when the workspace has none, so
     * the owner pays for a tenant whose every HTTP write has been refused since
     * the grace window closed.
     */
    #[Test]
    public function a_readonly_clients_run_resuming_after_a_wait_is_held(): void
    {
        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Wait Blocat SRL');

        // Both cost paths, asserted as never-called rather than by their output:
        // an assertion on the outbound row alone would still pass if we had
        // called the model and thrown the answer away.
        $this->mockSilentChatbotRunner();
        $this->mockOutboundChannel();

        $run = $this->makeRunParkedOnWait($workspace->id);

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        $this->assertSame(0, Message::where('direction', 'out')->count(),
            'A resumed run must not send for a read-only client.');

        // Held, not consumed: still 'waiting', still pointing at the node it was
        // going to run. A run marked failed or completed here never resumes even
        // after the invoice is paid.
        $fresh = $run->fresh();
        $this->assertSame('waiting', $fresh->status);
        $this->assertSame('n_send', $fresh->resume_node_id);
        $this->assertNull($fresh->error);
    }

    /**
     * Holding is only half of it: something has to wake the run up again, or the
     * client pays and the run sits in 'waiting' forever with nothing scanning
     * for it. The scheduled campaigns and posts are re-read every minute by
     * their own job; a parked run has no such sweeper, so it re-queues itself.
     */
    #[Test]
    public function a_held_run_requeues_itself_so_it_can_resume_after_they_pay(): void
    {
        // The sync driver ignores ->delay() and dispatches in-process, which
        // would re-enter handle() immediately; the job declines to re-queue
        // there. Production runs on the database driver, which is the shape
        // being pinned.
        config(['queue.default' => 'database']);
        Queue::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Wait Reprogramat SRL');

        $run = $this->makeRunParkedOnWait($workspace->id);

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        Queue::assertPushedOn('automation', ExecuteAutomationRunJob::class,
            fn (ExecuteAutomationRunJob $job) => $job->runId === $run->id);
    }

    /**
     * The control. Without it, "the run did not send" would also pass if the
     * guard had simply broken every resumed run for everyone.
     */
    #[Test]
    #[DataProvider('permittedStates')]
    public function an_entitled_clients_run_resumes_after_a_wait(string $state): void
    {
        [, , $workspace] = $this->makeClientInState($state, "Wait {$state} SRL");

        $this->mockOutboundChannel();

        $run = $this->makeRunParkedOnWait($workspace->id);

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        $this->assertDatabaseHas('messages', [
            'direction' => 'out',
            'body' => self::WAIT_NODE_REPLY,
        ]);
        $this->assertSame('completed', $run->fresh()->status);
    }

    // ─── 2c. A reply to an "Ask question" node ───────────────────────────────

    /**
     * The entitlement guard sits BELOW resumeAwaitingReplies, deliberately.
     *
     * A contact answers once. Consuming that answer finishes something the
     * tenant's own flow asked for; it is not the app starting new outbound work,
     * and it sends nothing — the wake-up job it queues is held by
     * ExecuteAutomationRunJob's own guard until the client is entitled again.
     * Dropping it instead left the run parked for ever with _awaiting_reply still
     * set: the client could pay a week later and it would never resume, because
     * the reply is not coming a second time.
     */
    #[Test]
    public function a_readonly_clients_contact_reply_is_kept_not_discarded(): void
    {
        Queue::fake();
        Notification::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Raspuns Blocat SRL');

        $conversation = $this->makeConversation($workspace->id);
        $run = $this->makeRunAwaitingReply($workspace->id, (int) $conversation->contact_id);

        MessageReceived::dispatch($this->makeInboundMessage($conversation));

        $fresh = $run->fresh();
        $this->assertArrayNotHasKey('_awaiting_reply', $fresh->context ?? []);
        $this->assertSame(self::INBOUND_BODY, $fresh->context['answer'] ?? null);
        $this->assertNotSame('waiting', $fresh->status);

        // Consuming the answer must not start the flow's outbound half here, and
        // must not start a second run from the same automation's trigger.
        $this->assertSame(0, $this->outboundCount($conversation));
        $this->assertSame(1, AutomationRun::count());
    }

    /**
     * The consequence the guard's old position produced, asserted directly: the
     * daily prune command would cancel the run with "No reply within 30 days",
     * which the tenant reads on their Runs page after paying, and which is false
     * — the contact did reply.
     */
    #[Test]
    public function a_kept_reply_is_never_cancelled_as_unanswered(): void
    {
        Queue::fake();
        Notification::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Raspuns Pastrat SRL');

        $conversation = $this->makeConversation($workspace->id);
        $run = $this->makeRunAwaitingReply($workspace->id, (int) $conversation->contact_id);

        MessageReceived::dispatch($this->makeInboundMessage($conversation));

        AutomationRun::whereKey($run->id)->update(['updated_at' => now()->subDays(60)]);

        $this->artisan('automation:prune-stale-runs')->assertSuccessful();

        $this->assertNotSame('cancelled', $run->fresh()->status);
        $this->assertNull($run->fresh()->error);
    }

    /**
     * The control: for an entitled client the same reply runs the flow through.
     * Without it, "the answer was stored" would also pass if resuming had been
     * broken for everyone.
     */
    #[Test]
    #[DataProvider('permittedStates')]
    public function an_entitled_clients_contact_reply_resumes_the_run(string $state): void
    {
        Notification::fake();

        [, , $workspace] = $this->makeClientInState($state, "Raspuns {$state} SRL");

        $this->mockOutboundChannel();

        $conversation = $this->makeConversation($workspace->id);
        $run = $this->makeRunAwaitingReply($workspace->id, (int) $conversation->contact_id);

        MessageReceived::dispatch($this->makeInboundMessage($conversation));

        $this->assertDatabaseHas('messages', [
            'direction' => 'out',
            'body' => self::ASK_NODE_REPLY,
        ]);
        $this->assertSame('completed', $run->fresh()->status);
    }

    // ─── 2d. The hold has a ceiling ──────────────────────────────────────────

    /**
     * The hourly re-queue is a fresh job, not release(), so $tries never applies
     * and nothing else reaps a held run: automation:prune-stale-runs only cancels
     * runs carrying _awaiting_reply, which a timed Wait never has. Without a
     * ceiling one churned tenant with 200 parked runs would leave 4,800 jobs a
     * day on the shared 'automation' queue for ever, and every later churn would
     * add its own share on top.
     */
    #[Test]
    public function a_run_held_past_the_ceiling_is_cancelled_instead_of_requeued_for_ever(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Wait Plafon SRL');

        $run = $this->makeRunParkedOnWait($workspace->id);

        // First hold: re-queued as before, and the ceiling clock starts here —
        // not at the moment the run was parked, which for a 30-day Wait node is
        // already past the ceiling on its very first wake-up.
        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        Queue::assertPushed(ExecuteAutomationRunJob::class, 1);
        $this->assertSame('waiting', $run->fresh()->status);

        $this->travel(((int) config('saas.held_run_max_days')) + 1)->days();

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        $fresh = $run->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        // The reason is what the Runs page shows, so it says what actually
        // happened rather than blaming the flow or the contact.
        $this->assertSame('Subscription lapsed while this run was waiting.', $fresh->error);

        // The chain ends: cancelling queued nothing new.
        Queue::assertPushed(ExecuteAutomationRunJob::class, 1);

        $this->travelBack();
    }

    /**
     * The ceiling measures one uninterrupted hold. A run that is held, released
     * when the client pays, parked on another Wait and held again months later
     * must not be cancelled on that second hold's first wake-up.
     */
    #[Test]
    public function a_run_held_and_then_released_forgets_its_ceiling(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();

        [, $client, $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Wait Eliberat SRL');

        $run = $this->makeRunParkedOnWait($workspace->id);

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));
        $this->assertArrayHasKey('_held_since', $run->fresh()->context ?? []);

        ClientSubscription::where('client_id', $client->id)->update([
            'ends_at' => now()->addMonth(),
            'status' => ClientSubscription::STATUS_ACTIVE,
        ]);
        Entitlement::forget();

        $this->mockOutboundChannel();

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        $fresh = $run->fresh();
        $this->assertArrayNotHasKey('_held_since', $fresh->context ?? []);
        $this->assertSame('completed', $fresh->status);
    }

    // ─── 3. Scheduled campaigns ──────────────────────────────────────────────

    #[Test]
    public function a_readonly_clients_due_campaign_is_held_and_stays_queued(): void
    {
        Queue::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Campanie Blocata SRL');

        $campaign = $this->makeDueCampaign($workspace->id);

        (new LaunchScheduledCampaignsJob)->handle();

        Queue::assertNotPushed(LaunchCampaignJob::class);

        // Held, not consumed: still 'queued', so the first tick after they pay
        // launches it. A campaign moved to 'sending' or 'failed' here would never
        // go out at all.
        $this->assertSame('queued', $campaign->fresh()->status);
    }

    #[Test]
    #[DataProvider('permittedStates')]
    public function an_entitled_clients_due_campaign_launches(string $state): void
    {
        Queue::fake();

        [, , $workspace] = $this->makeClientInState($state, "Campanie {$state} SRL");

        $campaign = $this->makeDueCampaign($workspace->id);

        (new LaunchScheduledCampaignsJob)->handle();

        Queue::assertPushedOn('broadcast', LaunchCampaignJob::class,
            fn (LaunchCampaignJob $job) => $job->campaignId === $campaign->id);
    }

    #[Test]
    public function a_client_paying_through_the_other_table_still_launches_campaigns(): void
    {
        Queue::fake();

        [$user, $client, $workspace] = $this->makeClient('Doua Tabele Campanii SRL');
        $this->adminGrant($client, now()->subDays(30), ClientSubscription::STATUS_EXPIRED);
        $this->gatewaySubscription($user, ['status' => 'active', 'ends_at' => now()->addDays(20)]);

        $campaign = $this->makeDueCampaign($workspace->id);

        (new LaunchScheduledCampaignsJob)->handle();

        Queue::assertPushed(LaunchCampaignJob::class,
            fn (LaunchCampaignJob $job) => $job->campaignId === $campaign->id);
    }

    // ─── 3b. A campaign that is already fanned out ───────────────────────────

    /**
     * LaunchScheduledCampaignsJob only gates a campaign at the moment it is
     * launched. A 100k-recipient campaign launched while still in grace has
     * already become a hundred chunk jobs, each queueing a thousand sends; if
     * grace ends mid-run — or the 'broadcast' queue is merely backed up — every
     * one of those still executes. For a workspace with no SMS or SMTP config of
     * its own, CredentialResolver bills them to the platform's own Twilio account
     * and sending reputation.
     */
    #[Test]
    public function a_readonly_clients_campaign_chunk_pauses_instead_of_fanning_out(): void
    {
        Queue::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Fanout Blocat SRL');

        $campaign = $this->makeSendingCampaign($workspace->id);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);

        (new DispatchCampaignChunkJob($campaign->id, [$contact->id]))->handle();

        Queue::assertNotPushed(SendCampaignMessageJob::class);

        // Paused rather than left in 'sending'. Nothing else moves a campaign out
        // of 'sending', and FinalizeCampaignJob would eventually mark it completed
        // with recipients that never went out; 'paused' is the product's own
        // resumable state, which the finalizer skips and launch() accepts back.
        $this->assertSame('paused', $campaign->fresh()->status);
    }

    #[Test]
    #[DataProvider('permittedStates')]
    public function an_entitled_clients_campaign_chunk_still_fans_out(string $state): void
    {
        Queue::fake();

        [, , $workspace] = $this->makeClientInState($state, "Fanout {$state} SRL");

        $campaign = $this->makeSendingCampaign($workspace->id);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);

        (new DispatchCampaignChunkJob($campaign->id, [$contact->id]))->handle();

        Queue::assertPushedOn('broadcast', SendCampaignMessageJob::class);
        $this->assertSame('sending', $campaign->fresh()->status);
    }

    /**
     * The window the chunk-level check cannot cover: the last chunk's thousand
     * sends are already queued and there is no further chunk job left to pause
     * the campaign.
     */
    #[Test]
    public function an_in_flight_send_for_a_readonly_client_holds_the_recipient(): void
    {
        Http::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Trimitere Blocata SRL');

        $campaign = $this->makeSendingCampaign($workspace->id);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $recipient = $this->makeQueuedRecipient($campaign, $contact);

        (new SendCampaignMessageJob($campaign->id, $contact->id))->handle(app(CampaignPersonalizer::class));

        // Held, not consumed: still 'queued' and not marked failed, so the
        // relaunch after they pay sends exactly the recipients that never went.
        $fresh = $recipient->fresh();
        $this->assertSame('queued', $fresh->status);
        $this->assertNull($fresh->failed_reason);

        $this->assertSame('paused', $campaign->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * The control. The workspace has no SMS provider, so the send throws and the
     * recipient is marked failed — which is the point: the job got as far as
     * trying, rather than being turned away at the entitlement guard.
     */
    #[Test]
    #[DataProvider('permittedStates')]
    public function an_entitled_clients_in_flight_send_is_still_attempted(string $state): void
    {
        Http::fake();

        [, , $workspace] = $this->makeClientInState($state, "Trimitere {$state} SRL");

        $campaign = $this->makeSendingCampaign($workspace->id);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $recipient = $this->makeQueuedRecipient($campaign, $contact);

        (new SendCampaignMessageJob($campaign->id, $contact->id))->handle(app(CampaignPersonalizer::class));

        $this->assertNotSame('queued', $recipient->fresh()->status);
        $this->assertSame('sending', $campaign->fresh()->status);
    }

    // ─── 4. Scheduled social posts ───────────────────────────────────────────

    #[Test]
    public function a_readonly_clients_due_post_is_held_and_stays_scheduled(): void
    {
        Queue::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Postare Blocata SRL');

        $post = $this->makeDuePost($workspace->id);

        (new DispatchScheduledPostsJob)->handle();

        Queue::assertNotPushed(PublishSocialPostJob::class);

        // THE BUG THIS TEST EXISTS FOR. Claiming the post ('publishing') and then
        // skipping it strands it: no code path ever returns a 'publishing' post to
        // 'scheduled', so the post would never publish even after the invoice is
        // paid. It must still be 'scheduled'.
        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'status' => 'scheduled']);
    }

    #[Test]
    #[DataProvider('permittedStates')]
    public function an_entitled_clients_due_post_is_dispatched(string $state): void
    {
        Queue::fake();

        [, , $workspace] = $this->makeClientInState($state, "Postare {$state} SRL");

        $post = $this->makeDuePost($workspace->id);

        (new DispatchScheduledPostsJob)->handle();

        Queue::assertPushedOn('social', PublishSocialPostJob::class,
            fn (PublishSocialPostJob $job) => $job->postId === $post->id);
        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'status' => 'publishing']);
    }

    /**
     * The stuck-post safety net re-publishes a post whose worker died mid-flight,
     * so it is a second way the same post could reach the client's accounts. It
     * is gated too — and the held post must be left *untouched*: touch() resets
     * the 30-minute staleness clock, and a post whose clock keeps being reset
     * every minute while the client is blocked would drop out of the net's own
     * window and never be rescued after they pay.
     */
    #[Test]
    public function a_readonly_clients_stuck_post_is_neither_requeued_nor_touched(): void
    {
        Queue::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Postare Blocata Agatata SRL');

        $post = $this->makeStuckPost($workspace->id);
        $staleClock = $post->fresh()->updated_at;

        (new DispatchScheduledPostsJob)->handle();

        Queue::assertNotPushed(PublishSocialPostJob::class);
        $this->assertSame(
            $staleClock->toDateTimeString(),
            $post->fresh()->updated_at->toDateTimeString(),
            'A held post must keep its stale clock, or the safety net can never pick it up again.'
        );
    }

    #[Test]
    public function an_entitled_clients_stuck_post_is_still_requeued(): void
    {
        Queue::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::ACTIVE, 'Postare Agatata SRL');

        $post = $this->makeStuckPost($workspace->id);

        (new DispatchScheduledPostsJob)->handle();

        Queue::assertPushedOn('social', PublishSocialPostJob::class,
            fn (PublishSocialPostJob $job) => $job->postId === $post->id);
    }

    // ─── Mixed tenants inside one scheduler tick ─────────────────────────────

    /**
     * One tick, two clients. Entitlement memoises per client id in a process-wide
     * static, so a memo that is not dropped between the two resolutions answers
     * the second client with the first client's verdict — and half the platform
     * either stops or leaks through, depending on which one is resolved first.
     */
    #[Test]
    public function one_scheduler_tick_holds_only_the_readonly_clients_campaign(): void
    {
        Queue::fake();

        [, , $blockedWorkspace] = $this->makeClientInState(Entitlement::READONLY, 'Tick Blocat SRL');
        [, , $payingWorkspace] = $this->makeClientInState(Entitlement::ACTIVE, 'Tick Platit SRL');

        $held = $this->makeDueCampaign($blockedWorkspace->id);
        $launched = $this->makeDueCampaign($payingWorkspace->id);

        (new LaunchScheduledCampaignsJob)->handle();

        Queue::assertPushed(LaunchCampaignJob::class, 1);
        Queue::assertPushed(LaunchCampaignJob::class,
            fn (LaunchCampaignJob $job) => $job->campaignId === $launched->id);
        Queue::assertNotPushed(LaunchCampaignJob::class,
            fn (LaunchCampaignJob $job) => $job->campaignId === $held->id);

        $this->assertSame('queued', $held->fresh()->status);
    }

    #[Test]
    public function one_scheduler_tick_holds_only_the_readonly_clients_post(): void
    {
        Queue::fake();

        [, , $blockedWorkspace] = $this->makeClientInState(Entitlement::READONLY, 'Tick Postare Blocata SRL');
        [, , $payingWorkspace] = $this->makeClientInState(Entitlement::ACTIVE, 'Tick Postare Platita SRL');

        $held = $this->makeDuePost($blockedWorkspace->id);
        $published = $this->makeDuePost($payingWorkspace->id);

        (new DispatchScheduledPostsJob)->handle();

        Queue::assertPushed(PublishSocialPostJob::class, 1);
        Queue::assertPushed(PublishSocialPostJob::class,
            fn (PublishSocialPostJob $job) => $job->postId === $published->id);

        $this->assertDatabaseHas('social_media_posts', ['id' => $held->id, 'status' => 'scheduled']);
        $this->assertDatabaseHas('social_media_posts', ['id' => $published->id, 'status' => 'publishing']);
    }

    // ─── They pay: the work resumes inside the same worker process ───────────

    /**
     * A queue worker lives for hours. If the entitlement verdict is memoised for
     * the life of the process, a customer who pays at 09:00 keeps getting nothing
     * until somebody restarts the worker — the worst outcome this whole feature
     * can produce, because it looks exactly like the product being broken.
     *
     * Both ticks run in one PHP process and the test calls no forget() between
     * them: the freshness has to come from the job itself, which drops the memo
     * at the top of handle() exactly because a tick can have a new answer.
     */
    #[Test]
    public function a_held_post_publishes_on_the_first_tick_after_the_client_pays(): void
    {
        Queue::fake();

        [, $client, $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Plateste Postare SRL');

        $post = $this->makeDuePost($workspace->id);

        (new DispatchScheduledPostsJob)->handle();
        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'status' => 'scheduled']);
        Queue::assertNothingPushed();

        // The invoice is paid. No forget() here on purpose.
        ClientSubscription::where('client_id', $client->id)->update([
            'ends_at' => now()->addMonth(),
            'status' => ClientSubscription::STATUS_ACTIVE,
        ]);

        (new DispatchScheduledPostsJob)->handle();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'status' => 'publishing']);
        Queue::assertPushedOn('social', PublishSocialPostJob::class,
            fn (PublishSocialPostJob $job) => $job->postId === $post->id);
    }

    #[Test]
    public function a_held_campaign_launches_on_the_first_tick_after_the_client_pays(): void
    {
        Queue::fake();

        [, $client, $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Plateste Campanie SRL');

        $campaign = $this->makeDueCampaign($workspace->id);

        (new LaunchScheduledCampaignsJob)->handle();
        Queue::assertNothingPushed();
        $this->assertSame('queued', $campaign->fresh()->status);

        ClientSubscription::where('client_id', $client->id)->update([
            'ends_at' => now()->addMonth(),
            'status' => ClientSubscription::STATUS_ACTIVE,
        ]);

        (new LaunchScheduledCampaignsJob)->handle();

        Queue::assertPushed(LaunchCampaignJob::class,
            fn (LaunchCampaignJob $job) => $job->campaignId === $campaign->id);
    }

    /**
     * The same promise for the listener path, which does not run inside one of
     * the two scheduler jobs and so cannot rely on their explicit forget(): the
     * inbound webhook is handled by ProcessInboundMessageJob, one queued job per
     * Meta delivery, and it is the job boundary that has to make the resolver
     * ask again. A memo held for the life of the worker would keep a paying
     * clinic's chatbot silent until somebody restarted the process.
     *
     * Driven with a closure job because the property belongs to the queue hook
     * in AppServiceProvider, not to any particular job — a job that calls
     * forget() itself would prove nothing about every other one.
     */
    #[Test]
    public function a_job_boundary_makes_the_resolver_ask_again(): void
    {
        [, $client, $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Memo Worker SRL');

        // Warm the memo the way the previous job on this worker would have.
        $this->assertSame(Entitlement::READONLY,
            Entitlement::state(Entitlement::clientForWorkspace($workspace->id)));

        ClientSubscription::where('client_id', $client->id)->update([
            'ends_at' => now()->addMonth(),
            'status' => ClientSubscription::STATUS_ACTIVE,
        ]);

        // Still 'readonly' without a boundary — that is the memo doing its job.
        $this->assertSame(Entitlement::READONLY,
            Entitlement::state(Entitlement::clientForWorkspace($workspace->id)));

        // A job starts. No forget() here on purpose.
        dispatch(function () {});

        $this->assertSame(Entitlement::ACTIVE,
            Entitlement::state(Entitlement::clientForWorkspace($workspace->id)),
            'A queue worker must not keep answering from the verdict it computed for an earlier job.');
    }

    /**
     * The other side of that memo. One Meta delivery carries up to twenty
     * messages, each dispatching MessageReceived to two listeners that both
     * resolve entitlement, all synchronously inside a webhook job Meta abandons
     * after a few seconds. Resolving per call cost eight queries per message —
     * 160 for a full batch — every one of them re-answering the identical
     * question, for paying tenants too.
     */
    #[Test]
    public function the_same_workspace_is_resolved_once_per_job_however_many_messages_arrive(): void
    {
        Queue::fake();
        Notification::fake();
        $this->mockChatbotRunnerAnyNumberOfTimes();
        $this->mockOutboundChannel();

        [, , $workspace] = $this->makeClientInState(Entitlement::ACTIVE, 'Lot Meta SRL');

        $conversation = $this->makeChatbotConversation($workspace->id);
        $messages = collect(range(1, 5))->map(fn () => $this->makeInboundMessage($conversation));

        $lookups = 0;
        DB::listen(function ($query) use (&$lookups) {
            // The one query clientForWorkspace() issues; matched on its exact
            // column list so an unrelated workspace read cannot inflate it.
            if (str_contains($query->sql, '`id`, `client_id` from `workspaces`')) {
                $lookups++;
            }
        });

        $messages->each(fn (Message $m) => MessageReceived::dispatch($m));

        $this->assertSame(1, $lookups,
            'Five inbound messages must cost one workspace lookup, not ten.');
    }

    // ─── Fail open ───────────────────────────────────────────────────────────

    /**
     * Three workspaces that cannot be resolved to an unpaid client, in one tick.
     * None of them is evidence that anybody stopped paying, so all three must
     * launch. Run together so the count assertion is exact — a guard that blocked
     * any one of them would drop the count to 2.
     */
    #[Test]
    public function campaigns_launch_for_every_workspace_whose_entitlement_cannot_be_resolved(): void
    {
        Queue::fake();

        // (a) a workspace with no client at all — the platform's own, or a
        //     workspace created before clients existed.
        $clientless = Workspace::factory()->create(['client_id' => null]);

        // (b) a workspace id that matches no row: the workspace was deleted but
        //     its campaigns were not.
        $danglingWorkspaceId = 999_777;
        $this->assertSame(0, Workspace::where('id', $danglingWorkspaceId)->count());

        // (c) a real client that was never given a plan. Not a debtor.
        [, , $noPlanWorkspace] = $this->makeClient('Fara Abonament SRL');

        $expected = [
            $this->makeDueCampaign($clientless->id)->id,
            $this->makeDueCampaign($danglingWorkspaceId)->id,
            $this->makeDueCampaign($noPlanWorkspace->id)->id,
        ];

        (new LaunchScheduledCampaignsJob)->handle();

        Queue::assertPushed(LaunchCampaignJob::class, 3);

        foreach ($expected as $campaignId) {
            Queue::assertPushed(LaunchCampaignJob::class,
                fn (LaunchCampaignJob $job) => $job->campaignId === $campaignId);
        }
    }

    #[Test]
    public function posts_dispatch_for_every_workspace_whose_entitlement_cannot_be_resolved(): void
    {
        Queue::fake();

        $clientless = Workspace::factory()->create(['client_id' => null]);
        $danglingWorkspaceId = 999_778;
        [, , $noPlanWorkspace] = $this->makeClient('Fara Abonament Postari SRL');

        $expected = [
            $this->makeDuePost($clientless->id)->id,
            $this->makeDuePost($danglingWorkspaceId)->id,
            $this->makeDuePost($noPlanWorkspace->id)->id,
        ];

        (new DispatchScheduledPostsJob)->handle();

        Queue::assertPushed(PublishSocialPostJob::class, 3);

        foreach ($expected as $postId) {
            $this->assertDatabaseHas('social_media_posts', ['id' => $postId, 'status' => 'publishing']);
        }
    }

    /**
     * A workspace with no client cannot be resolved to an entitlement, so the
     * chatbot must keep answering. Same rule as the jobs, different entry point.
     */
    #[Test]
    public function a_workspace_with_no_client_still_gets_a_chatbot_reply(): void
    {
        Notification::fake();

        $workspace = Workspace::factory()->create(['client_id' => null]);

        $this->mockChatbotRunner();
        $this->mockOutboundChannel();

        $conversation = $this->makeChatbotConversation($workspace->id);

        MessageReceived::dispatch($this->makeInboundMessage($conversation));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'body' => self::BOT_REPLY,
        ]);
    }

    // ─── The inbound message always arrives ──────────────────────────────────

    /**
     * The whole webhook path for a read-only client, driven from the raw Meta
     * payload rather than a hand-built Message, because the promise being tested
     * is about persistence *order*: the driver stores the message and updates the
     * conversation before it dispatches MessageReceived, and the guards all sit
     * inside the listeners.
     *
     * A patient messaging a dental clinic is not at fault for an unpaid invoice,
     * and a message that is not stored is lost forever.
     */
    #[Test]
    public function an_inbound_message_to_a_readonly_client_is_still_stored_and_visible(): void
    {
        Notification::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::READONLY, 'Cabinet Blocat SRL');

        $this->mockSilentChatbotRunner();

        $account = $this->makeChannelAccount($workspace->id, chatbot: true, phoneNumberId: 'pnid-readonly');

        app(WhatsappDriver::class)->processWebhookPayload($this->inboundWebhookPayload('pnid-readonly', 'wamid.blocat.1'));

        // Stored.
        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'wamid.blocat.1',
            'direction' => 'in',
            'body' => self::INBOUND_BODY,
        ]);

        // And visible: on this workspace's conversation, counted as unread, with
        // the conversation's last-inbound clock moved — this is what the inbox
        // lists and sorts by, so "stored" alone would not prove it shows up.
        $conversation = Conversation::where('workspace_id', $workspace->id)
            ->where('channel_account_id', $account->id)
            ->firstOrFail();

        $this->assertSame(1, $conversation->unread_count);
        $this->assertNotNull($conversation->last_inbound_at);
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->where('direction', 'in')->count());

        // Only the automatic answer is suppressed.
        $this->assertSame(0, $this->outboundCount($conversation));
    }

    /**
     * The control for the test above: the identical webhook, an entitled client,
     * and the reply genuinely appears. Without this, "no outbound row" would also
     * pass if the webhook path had quietly stopped replying for everyone.
     */
    #[Test]
    public function the_same_inbound_webhook_for_an_entitled_client_does_produce_a_reply(): void
    {
        Notification::fake();

        [, , $workspace] = $this->makeClientInState(Entitlement::ACTIVE, 'Cabinet Platit SRL');

        $this->mockChatbotRunner();
        $this->mockOutboundChannel();

        $account = $this->makeChannelAccount($workspace->id, chatbot: true, phoneNumberId: 'pnid-active');

        app(WhatsappDriver::class)->processWebhookPayload($this->inboundWebhookPayload('pnid-active', 'wamid.platit.1'));

        $conversation = Conversation::where('workspace_id', $workspace->id)
            ->where('channel_account_id', $account->id)
            ->firstOrFail();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'body' => self::BOT_REPLY,
        ]);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    /**
     * The runner must not be called at all — but it still answers if it is, so
     * that the "no outbound row" assertion is diagnostic on its own rather than
     * passing because a silent mock returned null.
     */
    private function mockSilentChatbotRunner(): void
    {
        $this->mock(ChatbotRunner::class, fn ($mock) => $mock->shouldNotReceive('run')->andReturn(self::BOT_REPLY));
    }

    private function mockChatbotRunner(): void
    {
        $this->mock(ChatbotRunner::class, fn ($mock) => $mock->shouldReceive('run')->once()->andReturn(self::BOT_REPLY));
    }

    /** For the batch test, where the number of replies is not what is being proved. */
    private function mockChatbotRunnerAnyNumberOfTimes(): void
    {
        $this->mock(ChatbotRunner::class, fn ($mock) => $mock->shouldReceive('run')->andReturn(self::BOT_REPLY));
    }

    /**
     * Nothing must reach Meta from a test, and the send result is not what is
     * being proved here — the outbound Message row is.
     */
    private function mockOutboundChannel(): void
    {
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturn('wamid.out.test');

        $this->mock(ChannelManager::class, fn ($mock) => $mock->shouldReceive('driver')->andReturn($driver));
    }

    private function makeChannelAccount(int $workspaceId, bool $chatbot = false, ?string $phoneNumberId = null): ChannelAccount
    {
        $chatbotId = null;

        if ($chatbot) {
            $chatbotId = AiChatbot::create([
                'workspace_id' => $workspaceId,
                'name' => 'Asistent',
                'enabled' => true,
            ])->id;
        }

        return ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'WA',
            'status' => 'active',
            'phone_number_id' => $phoneNumberId,
            'meta_json' => ['ai_chatbot_id' => $chatbotId],
        ]);
    }

    private function makeConversation(int $workspaceId, bool $chatbot = false): Conversation
    {
        $account = $this->makeChannelAccount($workspaceId, $chatbot);
        $contact = Contact::factory()->create(['workspace_id' => $workspaceId]);

        return Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => 'bot',
        ]);
    }

    private function makeChatbotConversation(int $workspaceId): Conversation
    {
        return $this->makeConversation($workspaceId, chatbot: true);
    }

    private function makeInboundMessage(Conversation $conversation): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => self::INBOUND_BODY,
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    /** An inbound message carrying one of AutoReplyListener's handover phrases. */
    private function makeHandoverRequest(Conversation $conversation): Message
    {
        $message = $this->makeInboundMessage($conversation);
        $message->update(['body' => self::HANDOVER_BODY]);

        return $message->refresh();
    }

    private function outboundCount(Conversation $conversation): int
    {
        return Message::where('conversation_id', $conversation->id)->where('direction', 'out')->count();
    }

    /** A keyword rule that matches self::INBOUND_BODY. */
    private function makeKeywordAutoReply(int $workspaceId): WhatsappAutoReply
    {
        return WhatsappAutoReply::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => null,
            'trigger_type' => 'keyword',
            'match_mode' => 'contains',
            'keywords' => ['loc joi'],
            'response_kind' => 'text',
            'payload_json' => ['text' => self::KEYWORD_REPLY],
            'enabled' => true,
            'priority' => 1,
        ]);
    }

    private function makeAutomation(int $workspaceId, string $triggerType): Automation
    {
        return Automation::create([
            'workspace_id' => $workspaceId,
            'name' => 'Test '.$triggerType,
            'status' => 'active',
            'trigger_type' => $triggerType,
            'nodes' => [['id' => 'n_trigger', 'type' => 'trigger', 'data' => []]],
            'edges' => [],
        ]);
    }

    /**
     * A run parked exactly as AutomationEngine::executeWait leaves it: status
     * 'waiting', the resume cursor on the node after the Wait, and a delayed
     * wake-up job in flight. Executing this job is the resumption.
     */
    private function makeRunParkedOnWait(int $workspaceId): AutomationRun
    {
        $this->makeChannelAccount($workspaceId);

        $automation = Automation::create([
            'workspace_id' => $workspaceId,
            'name' => 'Reamintire',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'nodes' => [
                ['id' => 'n_trigger', 'type' => 'trigger', 'data' => []],
                ['id' => 'n_wait', 'type' => 'wait', 'data' => ['amount' => 2, 'unit' => 'days']],
                ['id' => 'n_send', 'type' => 'send_whatsapp', 'data' => ['body' => self::WAIT_NODE_REPLY]],
            ],
            'edges' => [
                ['source' => 'n_trigger', 'target' => 'n_wait'],
                ['source' => 'n_wait', 'target' => 'n_send'],
            ],
        ]);

        return AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $workspaceId])->id,
            'status' => 'waiting',
            'current_node_id' => 'n_wait',
            'resume_node_id' => 'n_send',
            'context' => [],
            'started_at' => now()->subDays(2),
        ]);
    }

    /**
     * A run parked exactly as AutomationEngine's "Ask question" node leaves it:
     * status 'waiting', _awaiting_reply set, and the resume cursor on the node
     * that follows. The contact's next message is what resumes it.
     */
    private function makeRunAwaitingReply(int $workspaceId, int $contactId): AutomationRun
    {
        $automation = Automation::create([
            'workspace_id' => $workspaceId,
            'name' => 'Intrebare',
            'status' => 'active',
            'trigger_type' => 'message.received',
            'nodes' => [
                ['id' => 'n_trigger', 'type' => 'trigger', 'data' => []],
                ['id' => 'n_ask', 'type' => 'ask_question', 'data' => ['variable' => 'answer']],
                ['id' => 'n_send', 'type' => 'send_whatsapp', 'data' => ['body' => self::ASK_NODE_REPLY]],
            ],
            'edges' => [
                ['source' => 'n_trigger', 'target' => 'n_ask'],
                ['source' => 'n_ask', 'target' => 'n_send'],
            ],
        ]);

        return AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $contactId,
            'status' => 'waiting',
            'current_node_id' => 'n_ask',
            'resume_node_id' => 'n_send',
            'context' => ['_awaiting_reply' => true, '_reply_var' => 'answer'],
            'started_at' => now()->subDay(),
        ]);
    }

    /** A campaign mid-fan-out: already launched, recipients still draining. */
    private function makeSendingCampaign(int $workspaceId): Campaign
    {
        return Campaign::factory()->create([
            'workspace_id' => $workspaceId,
            'channel' => 'sms',
            'status' => 'sending',
            'payload_json' => ['body' => 'Reducere 10% saptamana aceasta.'],
        ]);
    }

    private function makeQueuedRecipient(Campaign $campaign, Contact $contact): CampaignRecipient
    {
        return CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
    }

    private function makeDueCampaign(int $workspaceId): Campaign
    {
        return Campaign::factory()->create([
            'workspace_id' => $workspaceId,
            'channel' => 'sms',
            'status' => 'queued',
            'schedule_at' => now()->subMinute(),
        ]);
    }

    private function makeDuePost(int $workspaceId): SocialPost
    {
        $account = SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => 'facebook',
            'account_id' => 'page_'.fake()->unique()->numerify('########'),
            'name' => 'Pagina',
            'access_token' => 'token-abc',
            'active' => true,
        ]);

        return SocialPost::create([
            'workspace_id' => $workspaceId,
            'body' => 'Programari disponibile joi.',
            'media_urls' => [],
            'target_accounts' => [$account->id],
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'timezone' => 'UTC',
        ]);
    }

    /** A post left in 'publishing' by a worker that died, old enough for the safety net. */
    private function makeStuckPost(int $workspaceId): SocialPost
    {
        $post = $this->makeDuePost($workspaceId);

        SocialPost::where('id', $post->id)->update([
            'status' => 'publishing',
            'updated_at' => now()->subMinutes(40),
        ]);

        return $post;
    }

    /**
     * A Meta inbound text message, in the shape processWebhookPayload() parses.
     *
     * @return array<string, mixed>
     */
    private function inboundWebhookPayload(string $phoneNumberId, string $messageId): array
    {
        return [
            'entry' => [[
                'id' => 'waba-test',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'id' => $messageId,
                            'from' => '40711223344',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => self::INBOUND_BODY],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
