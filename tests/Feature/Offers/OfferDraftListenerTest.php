<?php

namespace Tests\Feature\Offers;

use App\Events\MessageReceived;
use App\Listeners\AutomationTriggerListener;
use App\Listeners\AutoReplyListener;
use App\Listeners\DispatchOutboundWebhookListener;
use App\Listeners\SendNewMessageNotification;
use App\Models\ClientSetting;
use App\Models\ClientSubscription;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Inbox\Services\InstagramDriver;
use App\Modules\Offers\Listeners\DraftOfferFromMessageListener;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferDraftAttempt;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappAutoReply;
use App\Notifications\NewMessageNotification;
use App\Support\Entitlement;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsClientEntitlementStates;
use Tests\Concerns\DraftsOffersFromMessages;
use Tests\TestCase;

/**
 * The hook into the inbound message path — the highest-risk change in the offer
 * module, because it runs on every message arriving on all four channels, which
 * is the product.
 *
 * THE FIRST TWO CASES IN THIS FILE ARE THE ONES THAT MATTER. Everything else
 * here is a filter that saves money; those two are what stands between a bug in
 * the agent and a firm's inbox going quiet. Laravel's dispatcher calls listeners
 * in registration order with no try/catch of its own, so a listener that throws
 * silently cancels every listener after it — and after this one come the
 * automation trigger, the auto-reply and the new-message notification. The
 * argument that this is safe has exactly two halves, and each is a test:
 *
 *   1. It is registered FIRST, so nothing upstream can skip it.
 *   2. Its entire body is inside a catch, so nothing it gets wrong reaches the
 *      three listeners downstream.
 *
 * Neither half is worth anything alone. Registered first without a catch is
 * strictly worse than registered last: it would take the whole inbound path
 * down with it.
 */
class OfferDraftListenerTest extends TestCase
{
    use BuildsClientEntitlementStates;
    use DraftsOffersFromMessages;
    use RefreshDatabase;

    /** A message any reasonable intent gate has to let through. */
    private const PRICE_REQUEST = 'Bună ziua, cât costă un consult?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDraftFixtures();
        Notification::fake();
    }

    // ─── 1. the safety argument ──────────────────────────────────────────────

    /**
     * Registration order is load-bearing, so it is asserted rather than assumed.
     *
     * AppServiceProvider::boot() is the only place listeners are registered —
     * auto-discovery is off — and the drafter has to go in ahead of the three
     * that were already there.
     */
    public function test_the_drafter_is_the_first_listener_on_an_inbound_message(): void
    {
        $registered = $this->listenersFor(MessageReceived::class);

        $this->assertNotSame([], $registered, 'MessageReceived has no listeners at all');

        $this->assertStringContainsString(
            DraftOfferFromMessageListener::class,
            $registered[0],
            'The offer drafter must be registered FIRST on MessageReceived. Registered after another '.
            'listener it is the one silently skipped when that listener throws, and a dropped draft '.
            'is dropped for good: WhatsappDriver catches every throwable from inbound processing and '.
            'only logs, so nothing retries.',
        );

        // And it is ahead of each of them by name, not merely first in a list
        // that happened to be reordered.
        foreach ([
            AutomationTriggerListener::class,
            AutoReplyListener::class,
            DispatchOutboundWebhookListener::class,
            SendNewMessageNotification::class,
        ] as $downstream) {
            $this->assertLessThan(
                $this->indexOfListener($registered, $downstream),
                $this->indexOfListener($registered, DraftOfferFromMessageListener::class),
                "The drafter must run before {$downstream}.",
            );
        }
    }

    /**
     * Why being first is only half of it: the dispatcher really does stop.
     *
     * Without this the case above reads like a preference. It is not — a
     * listener registered first and allowed to throw takes every later listener
     * with it, which on this event means the automation trigger, the auto-reply
     * and the notification that tells the firm somebody wrote to them.
     */
    public function test_a_listener_that_throws_cancels_every_listener_after_it(): void
    {
        $dispatcher = new Dispatcher($this->app);
        $reached = false;

        $dispatcher->listen('probe.event', function (): void {
            throw new \RuntimeException('boom');
        });
        $dispatcher->listen('probe.event', function () use (&$reached): void {
            $reached = true;
        });

        try {
            $dispatcher->dispatch('probe.event');
        } catch (\Throwable) {
            // The throw escapes to the caller. That is the point.
        }

        $this->assertFalse(
            $reached,
            'Laravel wraps listeners in no try/catch, which is the entire reason the drafter carries its own.',
        );
    }

    /**
     * THE TEST THIS STAGE IS BUILT AROUND.
     *
     * The drafter cannot write its attempt row — which stands in for every way a
     * database can refuse a write at three in the morning: a full disk, a
     * failed-over replica, a lock timeout. The failure is injected at the model
     * rather than by dropping the table, because DDL commits MySQL's transaction
     * out from under RefreshDatabase and would leave every later test in the run
     * looking at a half-migrated schema.
     *
     * The customer's message must still reach the inbox, the firm's automation
     * must still fire, the auto-reply must still go out, and the firm must still
     * be told somebody wrote to them.
     */
    public function test_the_inbound_path_survives_a_drafter_that_cannot_write_its_row(): void
    {
        $this->enableDrafting();
        $this->arrangeDownstreamListeners();

        // The row is written on the synchronous path, after every filter has
        // passed — so this throws exactly where a real database error would.
        OfferDraftAttempt::creating(static function (): void {
            throw new \RuntimeException('the database is on fire');
        });

        $message = $this->deliver(self::PRICE_REQUEST);

        $this->assertDownstreamListenersRan();

        // Nothing was queued either: the listener got no further than the row.
        $this->assertSame([], $this->draftJobs());

        // And the customer's own words are where they always were.
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'direction' => 'in',
            'body' => self::PRICE_REQUEST,
        ]);
    }

    /**
     * The same guarantee, from the other end of the listener's body.
     *
     * The toggle is read before the row is written, so a failure here is the
     * first thing the listener does that touches a database. One catch has to
     * cover both ends, which is why the requirement is the WHOLE body and not
     * the part of it that looks risky.
     */
    public function test_the_inbound_path_survives_a_drafter_that_cannot_read_the_toggle(): void
    {
        $this->enableDrafting();
        $this->arrangeDownstreamListeners();

        ClientSetting::retrieved(static function (): void {
            throw new \RuntimeException('the settings store is unreachable');
        });

        $this->deliver(self::PRICE_REQUEST);

        $this->assertDownstreamListenersRan();
        $this->assertSame(0, OfferDraftAttempt::count());
        $this->assertSame([], $this->draftJobs());
    }

    /**
     * The control for the two cases above.
     *
     * Without it they would pass just as happily against a fixture where the
     * automation and the auto-reply never fire at all — and then they would be
     * asserting nothing.
     */
    public function test_the_downstream_listeners_do_run_when_nothing_is_broken(): void
    {
        $this->enableDrafting();
        $this->arrangeDownstreamListeners();

        $this->deliver(self::PRICE_REQUEST);

        $this->assertDownstreamListenersRan();

        // And the drafter did its own work as well as staying out of the way.
        $this->assertCount(1, $this->attempts());
    }

    // ─── what the listener is allowed to do ──────────────────────────────────

    /**
     * The whole contract: one row, one job, no network, no offer yet.
     *
     * The offer must NOT exist at this point. Building one means reading the
     * catalogue and holding an LLM connection open for as long as the provider
     * takes, and AutoReplyListener is the standing example of what that costs
     * inline — a blocking call of up to about 180 seconds inside a job whose
     * timeout is 120, on a queue Messenger and Instagram share.
     */
    public function test_a_price_request_writes_one_attempt_and_queues_the_work(): void
    {
        $this->enableDrafting();

        $message = $this->deliver(self::PRICE_REQUEST);

        $attempts = $this->attempts();
        $this->assertCount(1, $attempts);

        $attempt = $attempts->first();
        $this->assertSame($this->workspaceId(), (int) $attempt->workspace_id);
        $this->assertSame((int) $this->thread->id, (int) $attempt->conversation_id);
        $this->assertSame((int) $message->id, (int) $attempt->message_id);
        $this->assertSame(OfferDraftAttempt::STATUS_QUEUED, $attempt->status);
        $this->assertNull($attempt->offer_id);
        $this->assertSame(0, (int) $attempt->tokens);

        $jobs = $this->draftJobs();
        $this->assertCount(1, $jobs, 'exactly one drafting job belongs on the ai queue');

        // Delayed, because the debounce is the delay: a customer typing three
        // messages in fifteen seconds is one question, not three offers.
        $this->assertNotNull(
            $jobs[0]['job']->delay ?? null,
            'the drafting job must be delayed — an immediate job cannot debounce a burst',
        );

        $this->assertSame(0, Offer::count(), 'the listener must not build the offer itself');
        Http::assertNothingSent();
    }

    /**
     * The row is written BEFORE the job is dispatched, and that ordering is the
     * only thing that makes a dropped job recoverable.
     *
     * WhatsappDriver catches every throwable out of inbound processing and only
     * logs it, so the webhook is answered 200 and Meta never redelivers. If the
     * job were dispatched first and the row written after, a failure in between
     * would leave work queued that nothing on the platform knows was ever asked
     * for.
     */
    public function test_the_attempt_row_exists_before_the_job_could_possibly_run(): void
    {
        $this->enableDrafting();

        $this->deliver(self::PRICE_REQUEST);

        $this->assertCount(1, $this->attempts());
        $this->assertCount(1, $this->draftJobs());

        // Nothing has run the job yet, and the row is already there.
        $this->assertSame(
            OfferDraftAttempt::STATUS_QUEUED,
            $this->attempts()->first()->status,
        );
    }

    // ─── filter 1: direction ─────────────────────────────────────────────────

    public function test_an_outbound_message_prepares_nothing(): void
    {
        $this->enableDrafting();

        $this->deliver('Cât costă un consult? Vă trimit oferta.', ['direction' => 'out']);

        $this->assertNoDraftWasPrepared('the message was outbound');
    }

    /**
     * The reason filter 1 exists, driven through the real driver rather than
     * asserted from the source.
     *
     * InstagramDriver records a message the operator sent from the Instagram app
     * as an OUTBOUND message and then dispatches MessageReceived for it, exactly
     * as it does for a customer's own message. Without the direction filter,
     * every reply a firm types on Instagram would be read as somebody asking for
     * a quote — and an operator quoting a price in that reply would trigger a
     * draft addressed to the firm's own words.
     */
    public function test_an_instagram_echo_of_the_firms_own_reply_prepares_nothing(): void
    {
        $this->enableDrafting();

        $instagram = ChannelAccount::create([
            'workspace_id' => $this->workspaceId(),
            'channel' => 'instagram',
            'display_name' => 'Instagram',
            'status' => 'active',
            'credentials' => ['access_token' => 'ig-token'],
            'meta_json' => ['instagram_page_id' => '17841400000000001'],
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ionela Marin'], 200)]);

        app(InstagramDriver::class)->processWebhookPayload([
            'entry' => [[
                'id' => '17841400000000001',
                'messaging' => [[
                    // On an echo the business is the sender and the customer the
                    // recipient — the driver keys the thread on the recipient.
                    'sender' => ['id' => '17841400000000001'],
                    'recipient' => ['id' => 'CUSTOMER-IGSID'],
                    'message' => [
                        'mid' => 'mid.echo.1',
                        'is_echo' => true,
                        // Deliberately a sentence the intent gate would match.
                        'text' => 'Cât costă? Vă pregătim o ofertă acum.',
                    ],
                ]],
            ]],
        ]);

        // The driver really did announce it — this is the risk, not a hypothesis.
        $echo = Message::where('provider_message_id', 'mid.echo.1')->firstOrFail();
        $this->assertSame('out', $echo->direction);
        $this->assertSame($instagram->id, (int) $echo->conversation->channel_account_id);

        $this->assertSame(0, OfferDraftAttempt::count(), 'an operator’s own Instagram reply must not become a draft');
        $this->assertSame([], $this->draftJobs());
        $this->assertSame(0, Offer::count());
    }

    // ─── filter 2: a person already has it ───────────────────────────────────

    public function test_a_thread_a_person_has_taken_prepares_nothing(): void
    {
        $this->enableDrafting();

        $this->thread->update(['assigned_to' => 'human']);

        $this->deliver(self::PRICE_REQUEST);

        $this->assertNoDraftWasPrepared('a person had already taken the conversation');
    }

    public function test_a_thread_still_on_the_bot_prepares_a_draft(): void
    {
        $this->enableDrafting();

        $this->thread->update(['assigned_to' => 'bot']);

        $this->deliver(self::PRICE_REQUEST);

        $this->assertCount(1, $this->attempts());
    }

    // ─── filter 3: the per-firm switch ───────────────────────────────────────

    /**
     * The default, which is the single most important line in this stage: it
     * ships switched off, and is turned on one firm at a time once their
     * catalogue has been described.
     */
    public function test_drafting_is_off_until_a_firm_switches_it_on(): void
    {
        $this->assertDatabaseMissing('client_settings', ['key' => self::DRAFTING_TOGGLE]);

        $this->deliver(self::PRICE_REQUEST);

        $this->assertNoDraftWasPrepared('the firm had never switched drafting on');
    }

    public function test_switching_drafting_off_again_stops_it(): void
    {
        $this->enableDrafting(false);

        $this->deliver(self::PRICE_REQUEST);

        $this->assertNoDraftWasPrepared('the firm had switched drafting off');
    }

    /**
     * client_settings.value is a text column, and "false" is a truthy string in
     * PHP. A gate that opens on the stored word "false" would switch the feature
     * on for every firm at once.
     */
    public function test_the_switch_reads_the_stored_word_false_as_off(): void
    {
        ClientSetting::set($this->clientId(), self::DRAFTING_TOGGLE, 'false');

        $this->deliver(self::PRICE_REQUEST);

        $this->assertNoDraftWasPrepared('the toggle held the string "false"');
    }

    /** One firm's switch says nothing about another firm's. */
    public function test_one_firms_switch_does_not_turn_the_agent_on_for_another(): void
    {
        $other = $this->createWorkspaceContext();
        $this->enableDrafting(true, (int) $other['client']->id);

        $this->deliver(self::PRICE_REQUEST);

        $this->assertNoDraftWasPrepared('only a different firm had switched drafting on');
    }

    // ─── filter 4: entitlement ───────────────────────────────────────────────

    /**
     * A lapsed client must not spend the LLM key. CredentialResolver falls back
     * to the platform's own when a workspace has none, so an unpaid firm would
     * be spending the owner's credit on every inbound question it receives.
     */
    public function test_a_client_whose_subscription_lapsed_prepares_nothing(): void
    {
        $this->enableDrafting();

        $this->adminGrant($this->ctx['client'], now()->subDays(8), ClientSubscription::STATUS_EXPIRED);
        Entitlement::forget();

        $this->assertSame(
            Entitlement::READONLY,
            Entitlement::state($this->ctx['client']->fresh()),
            'the fixture was meant to be read-only',
        );
        Entitlement::forget();

        $message = $this->deliver(self::PRICE_REQUEST);

        $this->assertNoDraftWasPrepared('the client had run out of entitlement');

        // What stops is the paid work, not the inbox: the message the customer
        // sent is still stored and still in the thread.
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'direction' => 'in']);
    }

    /** A client inside the grace window is still a paying customer. */
    public function test_a_client_in_grace_still_gets_drafts(): void
    {
        $this->enableDrafting();

        $this->adminGrant($this->ctx['client'], now()->subDays(3), ClientSubscription::STATUS_EXPIRED);
        Entitlement::forget();

        $this->assertSame(Entitlement::GRACE, Entitlement::state($this->ctx['client']->fresh()));
        Entitlement::forget();

        $this->deliver(self::PRICE_REQUEST);

        $this->assertCount(1, $this->attempts());
    }

    // ─── filter 5: the intent gate ───────────────────────────────────────────

    /**
     * What a Romanian customer types when they want a price.
     *
     * Every one of these goes on its own thread, because the debounce is
     * conversation-scoped and a single thread would swallow all but the first.
     */
    public function test_a_message_that_asks_what_something_costs_prepares_a_draft(): void
    {
        $this->enableDrafting();

        $asking = [
            'cat costa un consult?',
            'Cât costă un consult?',
            'CÂT COSTĂ?',
            'Cât mă costă montajul?',
            'Cât ar fi pentru 40 mp?',
            'Aveți în stoc parchet stejar?',
            // 'Aveți ceva…' and 'Vreau două bucăți' were here and are gone on
            // purpose. Measured against thirty ordinary messages a dental
            // clinic receives, the bare verbs "aveti" and "vreau" fired on
            // twenty-seven of them — "Aveți loc mâine dimineață?", "Vreau să
            // anulez programarea". The gate exists to keep the firm from being
            // billed for every "mulțumesc", and the requests it misses are
            // still in the inbox where they always were.
            'Aș vrea o ofertă, vă rog',
            'Care e prețul?',
            'Îmi puteți face o ofertă?',
        ];

        foreach ($asking as $body) {
            $thread = $this->newThread();
            $this->deliver($body, [], $thread);

            $this->assertSame(
                1,
                OfferDraftAttempt::where('conversation_id', $thread->id)->count(),
                "The intent gate let nothing through for: {$body}",
            );
        }

        $this->assertCount(count($asking), $this->draftJobs());
    }

    /**
     * And what it must NOT fire on. Every inbound message on four channels goes
     * through this gate; a gate that fires on "mulțumesc" is a provider round
     * trip and a discarded offer for every polite customer a firm has.
     */
    public function test_a_message_with_no_buying_intent_prepares_nothing(): void
    {
        $this->enableDrafting();

        $chatter = [
            'Mulțumesc frumos, ne vedem mâine.',
            'Am primit coletul, totul e în regulă.',
            'Bună ziua!',
            'Da, confirm.',
            'Ok, super, o zi bună!',
        ];

        foreach ($chatter as $body) {
            $this->deliver($body, [], $this->newThread());
        }

        $this->assertNoDraftWasPrepared('none of the messages asked for a price');
    }

    /** An empty body is not a question, whatever else arrived with it. */
    public function test_a_message_with_no_text_prepares_nothing(): void
    {
        $this->enableDrafting();

        $this->deliver('   ', ['type' => 'image']);

        $this->assertNoDraftWasPrepared('the message carried no text at all');
    }

    // ─── the debounce, seen from the inbound side ────────────────────────────

    /**
     * Three messages, seconds apart, one question — and therefore one draft.
     *
     * Deliberately asserted at the listener rather than in the job: a second
     * attempt row would already be a second offer number and a second AI bill by
     * the time anything downstream could refuse it.
     */
    public function test_a_burst_of_messages_opens_one_attempt(): void
    {
        $this->enableDrafting();

        $this->deliver('Bună ziua, aveți parchet stejar?');
        $this->deliver('Cât costă?');
        $this->deliver('Cam 40 mp, la parter.');

        $this->assertCount(1, $this->attempts(), 'a burst in one thread is one question, not three offers');
        $this->assertCount(1, $this->draftJobs());
        $this->assertSame(3, Message::where('conversation_id', $this->thread->id)->where('direction', 'in')->count());
    }

    /** A different customer's thread is a different question. */
    public function test_a_burst_on_one_thread_does_not_silence_another(): void
    {
        $this->enableDrafting();

        $this->deliver('Cât costă?');
        $this->deliver('Cât costă?', [], $this->newThread());

        $this->assertCount(2, $this->attempts());
        $this->assertCount(2, $this->draftJobs());
    }

    /**
     * Meta redelivers a webhook it believes was not acknowledged, and two
     * workers can pick up the redelivery at the same instant. The unique index
     * on message_id is what makes a second attempt impossible rather than
     * unlikely — a cache check would let both through.
     */
    public function test_the_same_message_announced_twice_opens_one_attempt(): void
    {
        $this->enableDrafting();

        $message = $this->deliver(self::PRICE_REQUEST);

        // The debounce key is conversation-scoped and would hide a missing
        // unique index, so it is cleared before the redelivery: this case is
        // about the database constraint, not the debounce.
        Cache::flush();

        MessageReceived::dispatch($message);

        $this->assertCount(1, $this->attempts(), 'a redelivered webhook must not open a second attempt');
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * The listeners registered for an event, as readable strings, in the order
     * the dispatcher will call them.
     *
     * @return list<string>
     */
    private function listenersFor(string $event): array
    {
        $raw = app('events')->getRawListeners()[$event] ?? [];

        return array_values(array_map(static function ($listener): string {
            if (is_array($listener)) {
                return implode('@', array_map(static fn ($part) => is_string($part) ? $part : get_debug_type($part), $listener));
            }

            return is_string($listener) ? $listener : 'closure#'.spl_object_id($listener);
        }, $raw));
    }

    /** @param  list<string>  $registered */
    private function indexOfListener(array $registered, string $class): int
    {
        foreach ($registered as $index => $name) {
            if (str_contains($name, $class)) {
                return $index;
            }
        }

        $this->fail("{$class} is not registered on MessageReceived at all.");
    }

    /**
     * Give the two listeners downstream of the drafter something visible to do.
     *
     * An automation whose trigger is an inbound message, and a keyword
     * auto-reply that matches the same message. Both write a row, so "did it
     * run" is a database question rather than a mock.
     */
    private function arrangeDownstreamListeners(): void
    {
        Automation::create([
            'workspace_id' => $this->workspaceId(),
            'name' => 'Răspuns la mesaj',
            'status' => 'active',
            'trigger_type' => 'message.received',
            'trigger_config' => [],
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'triggerNode', 'position' => ['x' => 0, 'y' => 0], 'data' => ['triggerType' => 'message.received', 'label' => 'Trigger']],
                ['id' => 'add_tag-1', 'type' => 'add_tag', 'position' => ['x' => 0, 'y' => 120], 'data' => ['nodeType' => 'add_tag', 'tag' => 'a-intrebat-de-pret', 'configured' => true]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'add_tag-1', 'sourceHandle' => null, 'targetHandle' => null],
            ],
        ]);

        WhatsappAutoReply::create([
            'workspace_id' => $this->workspaceId(),
            'channel_account_id' => null,
            'trigger_type' => 'keyword',
            'match_mode' => 'contains',
            'keywords' => ['costa', 'costă'],
            'response_kind' => 'text',
            'payload_json' => ['text' => 'Vă răspundem imediat.'],
            'enabled' => true,
            'priority' => 1,
        ]);
    }

    /**
     * Both downstream listeners left their mark.
     *
     * The auto-reply's outbound row is asserted rather than a successful send:
     * there are no channel credentials in a test, so the send fails and the row
     * is marked failed — which still proves AutoReplyListener ran, and proving
     * that is the whole point.
     */
    private function assertDownstreamListenersRan(): void
    {
        $this->assertSame(
            1,
            AutomationRun::count(),
            'AutomationTriggerListener did not run — the drafter swallowed the inbound path with it.',
        );

        $this->assertSame(
            1,
            $this->outboundOnThread(),
            'AutoReplyListener did not run — the firm’s keyword reply never reached the customer.',
        );

        $this->assertNotEmpty(
            Notification::sent($this->ctx['user'], NewMessageNotification::class),
            'SendNewMessageNotification did not run — nobody at the firm was told a customer had written.',
        );
    }
}
