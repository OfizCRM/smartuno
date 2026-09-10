<?php

namespace Tests\Feature\Offers;

use App\Events\MessageReceived;
use App\Models\ClientSetting;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Offers\Exceptions\OfferDraftFailed;
use App\Modules\Offers\Jobs\DraftOfferJob;
use App\Modules\Offers\Listeners\DraftOfferFromMessageListener;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferDraftAttempt;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Offers\Services\OfferDrafter;
use App\Modules\Offers\Services\OfferNumberAllocator;
use App\Modules\Offers\Services\OfferSettings;
use App\Modules\Offers\Services\OfferTotals;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\NewMessageNotification;
use App\Support\Entitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A listener that throws inside process(), to prove the guarantee in the
 * production class's docblock. Bound over the real one in the container, which
 * is where the dispatcher resolves listeners from.
 */
class ExplodingDraftOfferListener extends DraftOfferFromMessageListener
{
    protected function process(MessageReceived $event): void
    {
        throw new \RuntimeException('the drafter blew up');
    }
}

/**
 * The drafter, without the provider.
 *
 * Bound over the real one so the job can be tested for what the job actually
 * does — pricing, tenancy, the offer row and the attempt row — without a single
 * HTTP call and without re-testing OfferDrafter, which has a suite of its own.
 */
class StubOfferDrafter extends OfferDrafter
{
    /** @var array<string, mixed> */
    public array $answer = [];

    public ?\Throwable $throws = null;

    public ?Collection $sawMessages = null;

    /** @var array<string, mixed>|null */
    public ?array $sawCorrection = null;

    public function draft(Conversation $conversation, Collection $messages, ?array $correction = null): array
    {
        $this->sawMessages = $messages;
        $this->sawCorrection = $correction;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->answer;
    }
}

/**
 * THE TEST THAT MATTERS MORE THAN ANY OTHER IN THIS STAGE.
 *
 * The offer drafter hooks App\Events\MessageReceived, which is the inbound
 * message path of all four channels — WhatsApp, Messenger, Instagram and email.
 * That is the product. Everything else in stage 4 can be wrong and the firm
 * still has an inbox; if this hook can break the event chain, a bug in an
 * experimental feature that ships switched off takes the whole product down with
 * it.
 *
 * So the first test here does not test drafting at all. It makes the drafter
 * throw and then proves that every listener behind it still ran and the message
 * still reached the person waiting for it.
 *
 * The rest pin the five filters, in the order the listener applies them, and the
 * conversation-scoped debounce.
 */
class DraftOfferInboundResilienceTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        Queue::fake();

        $this->ctx = $this->createWorkspaceContext();

        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);
        $channel = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'display_name' => 'WA',
            'status' => 'active',
        ]);

        $this->conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $channel->id,
            'status' => 'open',
            'assigned_to' => 'bot',
        ]);

        // The feature ships switched off. Every test that expects a draft turns
        // it on explicitly, which is itself the point of the toggle.
        $this->enableDrafting();
    }

    private function enableDrafting(bool $on = true): void
    {
        ClientSetting::set((int) $this->ctx['client']->id, 'offers.ai_drafting_enabled', $on ? '1' : '0');
    }

    private function inbound(string $body, string $direction = 'in'): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => $direction,
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $body,
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    private function receive(string $body, string $direction = 'in'): Message
    {
        $message = $this->inbound($body, $direction);
        MessageReceived::dispatch($message->load('conversation'));

        return $message;
    }

    // ─── the one that matters ────────────────────────────────────────────

    public function test_a_drafter_that_throws_cannot_stop_the_inbound_path(): void
    {
        // The dispatcher resolves [Class::class, 'handle'] out of the container,
        // so binding a throwing subclass is enough to poison the real
        // registration without touching AppServiceProvider.
        $this->app->bind(DraftOfferFromMessageListener::class, fn () => new ExplodingDraftOfferListener);

        // Registered now, so it sits BEHIND all four production listeners. If the
        // drafter's throw escaped its own catch, the dispatcher would stop and
        // this would never run.
        $downstreamRan = false;
        Event::listen(MessageReceived::class, function () use (&$downstreamRan): void {
            $downstreamRan = true;
        });

        $this->receive('cât costă un pachet de 40 mp?');

        $this->assertTrue(
            $downstreamRan,
            'A throw inside the offer drafter stopped the MessageReceived chain. '
            .'Every listener behind it — the automation trigger, the auto-reply, '
            .'the outbound webhook and the new-message notification — was skipped.'
        );

        // And the person waiting for the message was still told about it.
        Notification::assertSentTo($this->ctx['user'], NewMessageNotification::class);

        // Nothing half-written: a failure before the row is a failure with no row.
        $this->assertDatabaseCount('offer_draft_attempts', 0);
        Queue::assertNotPushed(DraftOfferJob::class);
    }

    // ─── the happy path ──────────────────────────────────────────────────

    public function test_a_price_question_writes_an_attempt_and_queues_the_job_on_the_ai_queue(): void
    {
        $message = $this->receive('Bună ziua, cât costă parchetul de stejar?');

        $attempt = OfferDraftAttempt::query()->firstOrFail();

        $this->assertSame((int) $this->ctx['workspace']->id, $attempt->workspace_id);
        $this->assertSame((int) $this->conversation->id, $attempt->conversation_id);
        $this->assertSame((int) $message->id, $attempt->message_id);
        $this->assertSame(OfferDraftAttempt::STATUS_QUEUED, $attempt->status);
        $this->assertNull($attempt->reason);
        $this->assertNull($attempt->offer_id);

        // 'ai', never 'whatsapp': Messenger and Instagram share that queue with
        // WhatsApp sends, and a slow completion there stalls three inboxes.
        Queue::assertPushed(
            DraftOfferJob::class,
            fn (DraftOfferJob $job): bool => $job->queue === 'ai'
                && $job->attemptId === (int) $attempt->id
                && $job->delay !== null
        );
    }

    public function test_diacritics_and_case_do_not_decide_whether_a_customer_is_understood(): void
    {
        // Comma-below ț (U+021B) — the correct Romanian letter.
        $this->receive('CARE E PREȚUL?');
        $this->assertDatabaseCount('offer_draft_attempts', 1);
    }

    public function test_the_cedilla_spelling_phones_actually_send_is_understood_too(): void
    {
        // Cedilla ţ (U+0163). Half the keyboards in Romania still send this one,
        // and a gate that only knew the correct letter would miss them all.
        $this->receive('care e preţul?');
        $this->assertDatabaseCount('offer_draft_attempts', 1);
    }

    // ─── filter 1: direction ─────────────────────────────────────────────

    public function test_an_outbound_echo_never_drafts(): void
    {
        // InstagramDriver dispatches MessageReceived for outbound echoes too.
        // Without the direction filter, every quote an operator types by hand
        // would be read as a customer asking for one.
        $this->receive('Vă trimit oferta: parchetul costă 240 lei', 'out');

        $this->assertDatabaseCount('offer_draft_attempts', 0);
        Queue::assertNotPushed(DraftOfferJob::class);
    }

    // ─── filter 2: a person already has it ───────────────────────────────

    public function test_a_thread_a_colleague_took_never_drafts(): void
    {
        $this->conversation->update(['assigned_to' => 'human']);

        $this->receive('cât costă?');

        $this->assertDatabaseCount('offer_draft_attempts', 0);
    }

    // ─── filter 3: the toggle ────────────────────────────────────────────

    public function test_it_ships_switched_off(): void
    {
        // No setting row at all — which is how every existing customer's
        // database looks the moment this deploys.
        ClientSetting::query()
            ->where('client_id', $this->ctx['client']->id)
            ->where('key', 'offers.ai_drafting_enabled')
            ->delete();

        $this->receive('cât costă?');

        $this->assertDatabaseCount('offer_draft_attempts', 0);
        Queue::assertNotPushed(DraftOfferJob::class);
    }

    public function test_the_string_false_does_not_switch_it_on(): void
    {
        // client_settings.value is a text column and "false" is truthy in PHP.
        // A boolean gate that opens on the string "false" ships the feature to
        // every customer at once.
        ClientSetting::set((int) $this->ctx['client']->id, 'offers.ai_drafting_enabled', 'false');

        $this->receive('cât costă?');

        $this->assertDatabaseCount('offer_draft_attempts', 0);
    }

    // ─── filter 4: entitlement ───────────────────────────────────────────

    public function test_a_client_whose_subscription_lapsed_never_drafts(): void
    {
        $plan = Plan::create([
            'name' => 'Basic',
            'slug' => 'basic-'.uniqid(),
            'price_cents' => 0,
            'currency_code' => 'RON',
            'interval' => 'monthly',
            'monthly_price_cents' => 0,
            'yearly_price_cents' => 0,
            'features' => [],
            'limits' => [],
            'enabled' => true,
        ]);

        ClientSubscription::create([
            'client_id' => $this->ctx['client']->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subYear(),
            // Long past the grace window.
            'ends_at' => now()->subYear(),
            'status' => ClientSubscription::STATUS_EXPIRED,
        ]);

        Entitlement::forget();

        $this->receive('cât costă?');

        // The message itself is still stored and still in the inbox — what stops
        // is the app spending the LLM key on a client who is not paying for it.
        $this->assertDatabaseCount('offer_draft_attempts', 0);
        $this->assertDatabaseHas('messages', ['conversation_id' => $this->conversation->id]);
    }

    // ─── filter 5: intent ────────────────────────────────────────────────

    public function test_an_ordinary_message_costs_nothing(): void
    {
        $this->receive('Mulțumesc frumos, ne vedem joi!');

        $this->assertDatabaseCount('offer_draft_attempts', 0);
        Queue::assertNotPushed(DraftOfferJob::class);
    }

    public function test_an_empty_body_costs_nothing(): void
    {
        $this->receive('   ');

        $this->assertDatabaseCount('offer_draft_attempts', 0);
    }

    // ─── the debounce ────────────────────────────────────────────────────

    public function test_three_messages_in_one_burst_produce_one_draft(): void
    {
        // What a customer actually does: a greeting, the question, then the size.
        // Three drafts would be three offer numbers and three AI bills for one
        // enquiry, and the first two would be built from half a sentence.
        $this->receive('bună ziua, aveți parchet stejar?');
        $this->receive('cât costă?');
        $this->receive('cam 40 mp');

        $this->assertDatabaseCount('offer_draft_attempts', 1);
        Queue::assertPushed(DraftOfferJob::class, 1);
    }

    public function test_a_new_question_later_gets_its_own_draft(): void
    {
        $this->receive('cât costă parchetul?');

        // The debounce window has passed — the customer is back with something
        // else, and that deserves an answer of its own.
        Cache::forget('offer_draft_debounce:'.$this->conversation->id);

        $this->receive('și cât costă montajul?');

        $this->assertDatabaseCount('offer_draft_attempts', 2);
    }

    public function test_a_redelivered_webhook_cannot_produce_a_second_attempt(): void
    {
        $message = $this->receive('cât costă?');

        // Meta redelivers a webhook it thinks was not acknowledged. The debounce
        // key is gone by then; the unique index on message_id is what holds.
        Cache::forget('offer_draft_debounce:'.$this->conversation->id);
        MessageReceived::dispatch($message->fresh()->load('conversation'));

        $this->assertDatabaseCount('offer_draft_attempts', 1);
        Queue::assertPushed(DraftOfferJob::class, 1);
    }

    // ─── the job ─────────────────────────────────────────────────────────

    private function item(array $attrs = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'workspace_id' => $this->ctx['workspace']->id,
            'type' => 'product',
            'name' => 'Parchet stejar 8mm',
            'code' => 'PS-8',
            'unit' => 'mp',
            'price_cents' => 12000,
            'min_price_cents' => 9000,
            'stock' => 100,
            'is_active' => true,
        ], $attrs));
    }

    /**
     * A complete answer in the shape OfferDrafter::draft() declares.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function answer(array $lines): array
    {
        return [
            'interpretation' => [
                'cere' => 'parchet stejar, 40 mp',
                'buget' => '',
                'termen' => 'săptămâna viitoare',
                'cerinte' => ['montaj inclus'],
                'pentru' => 'un apartament',
            ],
            'summary' => 'Am ales parchetul de stejar pentru că a cerut stejar.',
            'lines' => $lines,
            'dropped' => [],
            'warnings' => [],
            'reason_key' => $lines === [] ? OfferDrafter::REASON_NO_MATCH : null,
            'catalogue' => ['total' => 1, 'shown' => 1, 'narrowed' => false],
            'model' => 'claude-sonnet-4-6',
            'tokens' => 1234,
        ];
    }

    private function stub(): StubOfferDrafter
    {
        $stub = new StubOfferDrafter(app(LlmGateway::class));
        $this->app->instance(OfferDrafter::class, $stub);

        return $stub;
    }

    private function runJob(OfferDraftAttempt $attempt, ?array $interpretation = null): void
    {
        (new DraftOfferJob((int) $attempt->id, $interpretation))->handle(
            app(OfferTotals::class),
            app(OfferNumberAllocator::class),
            app(OfferSettings::class),
        );
    }

    private function queued(string $body = 'cât costă parchetul?'): OfferDraftAttempt
    {
        $this->receive($body);

        return OfferDraftAttempt::query()->latest('id')->firstOrFail();
    }

    public function test_the_job_turns_the_agents_answer_into_a_draft_offer(): void
    {
        $item = $this->item();
        $stub = $this->stub();
        $stub->answer = $this->answer([[
            'catalog_item_id' => $item->id,
            'name' => 'whatever the model called it',
            'unit' => 'x',
            'quantity' => 40.0,
            'unit_price_cents' => 12000,
            'reason' => 'A cerut stejar.',
            'out_of_stock' => false,
            'from_bundle' => null,
        ]]);

        $attempt = $this->queued();
        $this->runJob($attempt);

        $offer = Offer::query()->firstOrFail();

        $this->assertSame('ai', $offer->source);
        $this->assertSame('draft', $offer->status);
        $this->assertSame((int) $this->ctx['workspace']->id, $offer->workspace_id);
        $this->assertSame((int) $this->conversation->id, (int) $offer->conversation_id);
        $this->assertSame('whatsapp', $offer->channel);
        $this->assertNull($offer->created_by);
        $this->assertSame('OF-'.now()->format('Y').'-0001', $offer->number);

        // 40 mp x 120,00 lei = 4 800,00 lei, computed by OfferTotals from the
        // catalogue's own price — never from a figure the model wrote.
        $this->assertSame(480000, $offer->subtotal_cents);
        $this->assertSame(480000, $offer->total_cents);

        $line = OfferItem::query()->firstOrFail();
        $this->assertSame('ai', $line->getAttribute('added_by'));
        // The name and unit are the firm's own wording, not the model's.
        $this->assertSame('Parchet stejar 8mm', $line->name);
        $this->assertSame('mp', $line->unit);
        $this->assertSame('40.000', (string) $line->getAttribute('quantity'));
        $this->assertSame(12000, $line->unit_price_cents);

        $this->assertSame('Am ales parchetul de stejar pentru că a cerut stejar.', $offer->ai_reason);
        $this->assertSame('parchet stejar, 40 mp', $offer->ai_json['interpretation']['cere']);
        $this->assertSame([$attempt->message_id], $offer->ai_json['source_message_ids']);
        $this->assertFalse($offer->ai_json['corrected']);

        $attempt->refresh();
        $this->assertSame(OfferDraftAttempt::STATUS_DRAFTED, $attempt->status);
        $this->assertSame((int) $offer->id, $attempt->offer_id);
        $this->assertSame(1234, $attempt->tokens);
        $this->assertNull($attempt->reason);
    }

    public function test_a_price_the_model_invented_is_clamped_into_the_firms_own_band(): void
    {
        $item = $this->item(['price_cents' => 12000, 'min_price_cents' => 9000]);
        $stub = $this->stub();
        $stub->answer = $this->answer([[
            'catalog_item_id' => $item->id,
            'name' => 'Parchet',
            'unit' => 'mp',
            'quantity' => 1.0,
            // One ban. A model that discounts on its own behalf must not be able
            // to quote below what the firm said it would accept.
            'unit_price_cents' => 1,
            'reason' => '',
            'out_of_stock' => false,
            'from_bundle' => null,
        ]]);

        $this->runJob($this->queued());

        $this->assertSame(9000, OfferItem::query()->firstOrFail()->unit_price_cents);
    }

    public function test_another_firms_catalogue_id_never_reaches_the_offer(): void
    {
        $other = $this->createWorkspaceContext();
        $theirs = CatalogItem::create([
            'workspace_id' => $other['workspace']->id,
            'type' => 'product',
            'name' => 'Produsul altei firme',
            'unit' => 'buc',
            'price_cents' => 99000,
            'is_active' => true,
        ]);

        $stub = $this->stub();
        $stub->answer = $this->answer([[
            'catalog_item_id' => $theirs->id,
            'name' => 'Produsul altei firme',
            'unit' => 'buc',
            'quantity' => 1.0,
            'unit_price_cents' => 99000,
            'reason' => '',
            'out_of_stock' => false,
            'from_bundle' => null,
        ]]);

        $attempt = $this->queued();
        $this->runJob($attempt);

        // Nothing quoted, nothing leaked, and the firm is told why.
        $this->assertSame(0, Offer::query()->count());
        $this->assertSame(0, OfferItem::query()->count());

        $attempt->refresh();
        $this->assertSame(OfferDraftAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(OfferDraftAttempt::REASON_NO_MATCH, $attempt->reason);
    }

    public function test_a_failed_draft_records_a_reason_and_sends_the_customer_nothing(): void
    {
        $this->item();
        $stub = $this->stub();
        $stub->throws = new OfferDraftFailed(
            OfferDrafter::REASON_PROVIDER_FAILED,
            'Furnizorul AI nu a răspuns.',
        );

        $attempt = $this->queued();
        $before = Message::query()->count();

        $this->runJob($attempt);

        $attempt->refresh();
        $this->assertSame(OfferDraftAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(OfferDrafter::REASON_PROVIDER_FAILED, $attempt->reason);
        $this->assertSame(0, Offer::query()->count());

        // The one that matters: ChatbotRunner answers a broken provider with a
        // fallback sentence to a real customer. This must not.
        $this->assertSame($before, Message::query()->count());
    }

    public function test_a_provider_body_never_reaches_the_reason_column(): void
    {
        $this->item();
        $stub = $this->stub();
        // What OpenAI actually answers a dead key with.
        $stub->throws = new \RuntimeException('Incorrect API key provided: sk-abc123. You can find your API key at https://platform.openai.com/account/api-keys.');

        $attempt = $this->queued();
        $this->runJob($attempt);

        $attempt->refresh();
        $this->assertSame(OfferDraftAttempt::REASON_UNEXPECTED, $attempt->reason);
        $this->assertStringNotContainsString('sk-', (string) $attempt->reason);
    }

    public function test_the_same_attempt_cannot_be_drafted_twice(): void
    {
        $item = $this->item();
        $stub = $this->stub();
        $stub->answer = $this->answer([[
            'catalog_item_id' => $item->id,
            'name' => 'Parchet',
            'unit' => 'mp',
            'quantity' => 1.0,
            'unit_price_cents' => 12000,
            'reason' => '',
            'out_of_stock' => false,
            'from_bundle' => null,
        ]]);

        $attempt = $this->queued();
        $this->runJob($attempt);
        // A re-dispatch, an operator retry, a worker started with --tries=3.
        $this->runJob($attempt->fresh());

        // One offer, and one number out of the firm's series.
        $this->assertSame(1, Offer::query()->count());
    }

    public function test_a_colleague_taking_the_thread_during_the_debounce_skips_the_draft(): void
    {
        $this->item();
        $this->stub();

        $attempt = $this->queued();
        $this->conversation->update(['assigned_to' => 'human']);

        $this->runJob($attempt);

        $attempt->refresh();
        $this->assertSame(OfferDraftAttempt::STATUS_SKIPPED, $attempt->status);
        $this->assertSame(OfferDraftAttempt::REASON_HANDOVER, $attempt->reason);
        $this->assertSame(0, Offer::query()->count());
    }

    public function test_a_regenerate_starts_from_the_corrected_reading(): void
    {
        $item = $this->item();
        $stub = $this->stub();
        $stub->answer = $this->answer([[
            'catalog_item_id' => $item->id,
            'name' => 'Parchet',
            'unit' => 'mp',
            'quantity' => 1.0,
            'unit_price_cents' => 12000,
            'reason' => '',
            'out_of_stock' => false,
            'from_bundle' => null,
        ]]);

        $attempt = $this->queued();
        $this->runJob($attempt);

        $offer = Offer::query()->firstOrFail();
        $number = $offer->number;

        // The person fixed what the agent understood and asked again.
        $attempt->refresh()->forceFill(['status' => OfferDraftAttempt::STATUS_QUEUED])->save();
        $correction = ['cere' => 'laminat, nu stejar', 'buget' => '2000 lei', 'termen' => '', 'cerinte' => [], 'pentru' => ''];
        $this->runJob($attempt->fresh(), $correction);

        $this->assertSame($correction, $stub->sawCorrection);

        // Still one offer, and still the number the firm may already have said
        // on the phone.
        $this->assertSame(1, Offer::query()->count());
        $this->assertSame($number, Offer::query()->firstOrFail()->number);
        $this->assertTrue(Offer::query()->firstOrFail()->ai_json['corrected']);
    }
}
