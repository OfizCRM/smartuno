<?php

namespace Tests\Concerns;

use App\Events\MessageReceived;
use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Models\CatalogItemLink;
use App\Modules\Catalog\Models\CatalogItemTag;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferDraftAttempt;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Support\Entitlement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Response as PromiseResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The fixtures for stage 4: an inbound customer message on a real thread, a
 * catalogue to quote from, and the switch that lets the agent touch either.
 *
 * WHY THE QUEUE IS ALWAYS FAKED HERE. phpunit.xml runs on the sync driver, and
 * SyncQueue ignores ->delay() entirely — so the drafting job would run inline,
 * inside the listener, on the webhook request. That is the one shape this stage
 * exists to avoid, and testing against it would prove the opposite of what the
 * suite claims: a debounce measured on a driver with no delay is not a debounce.
 * Queue::fake() captures what was pushed and where, runQueuedDrafts() runs it
 * afterwards, and the gap between the two is where "never inline" is asserted.
 *
 * NOTHING HERE NAMES THE JOB CLASS. draftJobs() finds the work by the queue it
 * was put on ('ai'), which is part of the requirement rather than an
 * implementation detail — the drafting job must not share the 'whatsapp' queue
 * that Messenger and Instagram sends already contend for.
 */
trait DraftsOffersFromMessages
{
    /**
     * The queue the drafting job must be put on. Not 'whatsapp', which carries
     * WhatsApp, Messenger and Instagram sends, and not the default.
     */
    private const AI_QUEUE = 'ai';

    /** The per-firm switch. Absent means off, which is how the stage ships. */
    private const DRAFTING_TOGGLE = 'offers.ai_drafting_enabled';

    /** @var array{user: User, workspace: Workspace, client: Client} */
    private array $ctx;

    private ChannelAccount $channelAccount;

    /** Swapped by fakeOpenAi(); see fakeTheNetwork() for why it is a property. */
    private $openAiResponder = null;

    private Contact $customer;

    private Conversation $thread;

    /**
     * A firm, a channel, a customer and an open thread — the state the inbound
     * path always finds. Drafting is left OFF, because that is the default and
     * every test that needs it on should have to say so.
     */
    private function bootDraftFixtures(string $channel = 'whatsapp'): void
    {
        $this->ctx = $this->createWorkspaceContext();

        $this->channelAccount = ChannelAccount::create([
            'workspace_id' => $this->workspaceId(),
            'channel' => $channel,
            'display_name' => 'Canalul firmei',
            'status' => 'active',
        ]);

        $this->customer = Contact::factory()->create([
            'workspace_id' => $this->workspaceId(),
            'first_name' => 'Ionela',
            'last_name' => 'Marin',
        ]);

        $this->thread = $this->newThread();

        Queue::fake();
        $this->fakeTheNetwork();

        Entitlement::forget();
    }

    private function workspaceId(): int
    {
        return (int) $this->ctx['workspace']->id;
    }

    private function clientId(): int
    {
        return (int) $this->ctx['client']->id;
    }

    /**
     * A second thread with the same customer.
     *
     * The debounce is conversation-scoped, so a test that delivers several
     * unrelated messages has to put them on separate threads or it is measuring
     * the debounce rather than whatever it meant to measure.
     */
    private function newThread(?int $workspaceId = null, ?int $channelAccountId = null): Conversation
    {
        return Conversation::create([
            'workspace_id' => $workspaceId ?? $this->workspaceId(),
            'contact_id' => $this->customer->id,
            'channel_account_id' => $channelAccountId ?? $this->channelAccount->id,
            'status' => 'open',
        ]);
    }

    /** Switch drafting on (or off) for a firm, the way the settings screen does. */
    private function enableDrafting(bool $on = true, ?int $clientId = null): void
    {
        ClientSetting::set($clientId ?? $this->clientId(), self::DRAFTING_TOGGLE, $on ? '1' : '0');
    }

    /**
     * One catalogue line. Priced at 240,00 lei with a 200,00 lei floor unless a
     * test says otherwise, so "clamped to the floor" and "clamped to the list
     * price" are two different numbers.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function catalogueItem(array $attrs = [], ?int $workspaceId = null): CatalogItem
    {
        static $n = 0;
        $n++;

        return CatalogItem::create(array_merge([
            'workspace_id' => $workspaceId ?? $this->workspaceId(),
            'type' => 'product',
            'name' => 'Produs '.$n,
            'code' => 'P-'.$n,
            'category' => 'Materiale',
            'unit' => 'buc',
            'price_cents' => 24000,
            'min_price_cents' => 20000,
            'is_active' => true,
        ], $attrs));
    }

    /** A red "nu îl propune dacă" chip, or a green "pentru cine este" one. */
    private function tag(CatalogItem $item, string $kind, string $label): CatalogItemTag
    {
        return CatalogItemTag::create([
            'workspace_id' => (int) $item->workspace_id,
            'catalog_item_id' => $item->id,
            'kind' => $kind,
            'label' => $label,
            'source' => 'human',
        ]);
    }

    /** A component of a bundle, in the order it goes onto an offer. */
    private function bundleComponent(CatalogItem $bundle, CatalogItem $part, string $quantity = '1', int $position = 0): CatalogItemLink
    {
        return CatalogItemLink::create([
            'workspace_id' => (int) $bundle->workspace_id,
            'catalog_item_id' => $bundle->id,
            'related_item_id' => $part->id,
            'kind' => CatalogItemLink::KIND_BUNDLE_COMPONENT,
            'quantity' => $quantity,
            'position' => $position,
        ]);
    }

    /**
     * A message on the thread, stored exactly as a driver stores it, and then
     * announced exactly as a driver announces it.
     *
     * Stored first and announced second on purpose: that is the order every
     * driver uses, and it is the reason a listener that throws cannot lose the
     * customer's words — they are already in the inbox before any listener runs.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function deliver(string $body, array $attrs = [], ?Conversation $thread = null): Message
    {
        $thread ??= $this->thread;

        $message = Message::create(array_merge([
            'conversation_id' => $thread->id,
            'direction' => 'in',
            'channel' => $this->channelAccount->channel,
            'type' => 'text',
            'body' => $body,
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ], $attrs));

        // The relation is set by hand so the listener reads the same object the
        // test holds. A driver has it loaded too; re-querying here would hide a
        // listener that depends on a fresh read.
        $message->setRelation('conversation', $thread);

        MessageReceived::dispatch($message);

        return $message;
    }

    // ─── what the listener put on the queue ──────────────────────────────────

    /**
     * Everything pushed onto the 'ai' queue, in the order it was pushed.
     *
     * Found by queue rather than by class name: "onto 'ai', never onto the queue
     * three channels' sends already share" is the requirement, and asserting it
     * by class would let a rename quietly move the work back onto 'whatsapp'.
     *
     * @return list<array{job: object, queue: string|null, class: string}>
     */
    private function draftJobs(): array
    {
        $found = [];

        foreach (Queue::pushedJobs() as $class => $entries) {
            foreach ($entries as $entry) {
                $queue = $entry['queue'] ?? ($entry['job']->queue ?? null);

                if ($queue === self::AI_QUEUE) {
                    $found[] = ['job' => $entry['job'], 'queue' => $queue, 'class' => $class];
                }
            }
        }

        return $found;
    }

    /**
     * Run what the listener queued, the way a worker eventually would.
     *
     * handle() is called through the container so its dependencies resolve, the
     * same way tests already run ExecuteAutomationRunJob.
     */
    private function runQueuedDrafts(): int
    {
        $ran = 0;

        foreach ($this->draftJobs() as $entry) {
            app()->call([$entry['job'], 'handle']);
            $ran++;
        }

        return $ran;
    }

    // ─── what came of it ─────────────────────────────────────────────────────

    /** @return Collection<int, OfferDraftAttempt> */
    private function attempts(?int $workspaceId = null)
    {
        return OfferDraftAttempt::where('workspace_id', $workspaceId ?? $this->workspaceId())
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Offer> */
    private function aiOffers(?int $workspaceId = null)
    {
        return Offer::where('workspace_id', $workspaceId ?? $this->workspaceId())
            ->where('source', 'ai')
            ->orderBy('id')
            ->get();
    }

    /** Outbound messages on the thread — what the customer would have received. */
    private function outboundOnThread(?Conversation $thread = null): int
    {
        return Message::where('conversation_id', ($thread ?? $this->thread)->id)
            ->where('direction', 'out')
            ->count();
    }

    /**
     * Nothing was prepared, nothing was queued, and nothing was spent.
     *
     * All three, because the three failures look identical from the outside and
     * only one of them is cheap: a filter that lets the job through but has it
     * decline still costs a queue round trip, and one that lets the provider be
     * called costs the firm money.
     */
    private function assertNoDraftWasPrepared(string $why): void
    {
        $this->assertSame(0, OfferDraftAttempt::count(), "an attempt row was written when {$why}");
        $this->assertSame([], $this->draftJobs(), "drafting was queued when {$why}");
        $this->assertSame(0, Offer::count(), "an offer was created when {$why}");
        Http::assertNothingSent();
    }

    // ─── the provider ────────────────────────────────────────────────────────

    /**
     * Point the workspace at OpenAI with a key that would be a secret if this
     * were real — so a test can prove the key never reaches a column or a screen.
     */
    private function useOpenAi(?int $workspaceId = null): AiProviderConfig
    {
        return AiProviderConfig::create([
            'workspace_id' => $workspaceId ?? $this->workspaceId(),
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test-secret'],
            // Deliberately the cheap chat model the settings screen offers. The
            // gateway pins its own for structured work, and a test asserts that
            // this value is NOT what gets sent.
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);
    }

    /**
     * Every outbound HTTP call, faked, once — and the LLM answer made swappable.
     *
     * Registered exactly once per test, because Http::fake() MERGES stubs and the
     * FIRST matching one wins: a catch-all registered in setUp silently shadows
     * every specific stub a test adds afterwards, and the drafter then reads an
     * empty 200 as an empty answer. That failure is invisible in any test that
     * only asserts on the request, which is precisely how it would survive.
     *
     * So the provider stub is one closure that defers to $openAiResponder, and
     * a test swaps the responder rather than the stub.
     */
    private function fakeTheNetwork(): void
    {
        $this->openAiResponder = null;

        Http::fake([
            'api.openai.com/v1/chat/completions' => function ($request) {
                $responder = $this->openAiResponder ?? fn (): PromiseResponse => Http::response(
                    $this->openAiBody($this->emptyAnswer()),
                    200,
                );

                return $responder($request);
            },
            // Everything else — a contact-profile lookup, a channel send — is a
            // faked 200. Nothing in this stage should be reaching any of it, and
            // the tests that care assert that with Http::assertNothingSent().
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * What the provider answers next.
     *
     * @param  array<string, mixed>|string  $payload
     */
    private function fakeOpenAi(array|string $payload, int $completionTokens = 120): void
    {
        $this->fakeOpenAiRaw(fn () => Http::response($this->openAiBody($payload, $completionTokens), 200));
    }

    /** The same, for an answer that is not a well-formed 200 at all. */
    private function fakeOpenAiRaw(callable $responder): void
    {
        $this->openAiResponder = $responder;
    }

    /**
     * A well-formed answer in the shape the drafter's schema asks for, choosing
     * nothing. The default, so a test that forgets to fake gets a clean "nothing
     * suitable" rather than an unreadable one.
     *
     * @return array<string, mixed>
     */
    private function emptyAnswer(): array
    {
        return [
            'interpretare' => ['cere' => '', 'buget' => '', 'termen' => '', 'cerinte' => [], 'pentru' => ''],
            'produse' => [],
            'rezumat' => '',
        ];
    }

    /**
     * @param  array<string, mixed>|string  $payload
     * @return array<string, mixed>
     */
    private function openAiBody(array|string $payload, int $completionTokens = 120): array
    {
        return [
            'choices' => [[
                'message' => ['content' => is_string($payload)
                    ? $payload
                    : (string) json_encode($payload, JSON_UNESCAPED_UNICODE)],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 300, 'completion_tokens' => $completionTokens],
            'model' => 'gpt-4o',
        ];
    }

    /**
     * Every request body sent to OpenAI's chat endpoint, decoded.
     *
     * @return list<array<string, mixed>>
     */
    private function openAiRequests(): array
    {
        $bodies = [];

        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), 'openai.com')) {
                continue;
            }

            $decoded = json_decode($request->body(), true);

            if (is_array($decoded)) {
                $bodies[] = $decoded;
            }
        }

        return $bodies;
    }

    /** @return array<string, mixed> */
    private function lastOpenAiRequest(): array
    {
        $bodies = $this->openAiRequests();

        $this->assertNotSame([], $bodies, 'the provider was never called');

        return (array) end($bodies);
    }

    /** Every prompt turn of the last call, run together — system included. */
    private function lastPrompt(): string
    {
        $body = $this->lastOpenAiRequest();
        $text = '';

        foreach ((array) ($body['messages'] ?? []) as $turn) {
            $text .= "\n".(string) ($turn['content'] ?? '');
        }

        return $text;
    }
}
