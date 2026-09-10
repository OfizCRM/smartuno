<?php

namespace Tests\Feature\Offers;

use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Models\CatalogItemTag;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferDraftAttempt;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Offers\Services\OfferDrafter;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\DraftsOffersFromMessages;
use Tests\TestCase;

/**
 * What the agent is allowed to propose, and what happens when it cannot.
 *
 * TWO PROPERTIES HOLD THIS WHOLE STAGE UP.
 *
 * The first is that THE MODEL NEVER TOUCHES MONEY. It is handed catalogue ids
 * and it hands back catalogue ids and quantities; every figure that reaches a
 * row is computed in PHP from prices read out of catalog_items. So the cases
 * below feed it deliberately hostile answers — an id from another firm, an id
 * that never existed, a bundle sold as one line, a price under the floor the
 * firm set, a product it has none of — and assert on what ends up in
 * offer_items, which is the only place a customer can be shown a wrong number.
 *
 * The second is that FAILURE IS LOUD AND THE CUSTOMER HEARS NOTHING.
 * App\Modules\AI\Services\ChatbotRunner is the counter-example in this codebase:
 * it swallows every provider error into a fallback sentence and sends that to a
 * real person, so a dead API key is indistinguishable from a working bot. Here a
 * failure writes 'failed' on the attempt with a Romanian reason the firm can
 * read, sends the customer nothing at all, and never puts the provider's own
 * words — which carry the API key — anywhere a person could see them.
 */
class OfferDraftingTest extends TestCase
{
    use DraftsOffersFromMessages;
    use RefreshDatabase;

    private const PRICE_REQUEST = 'Bună ziua, cât costă un consult complet?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDraftFixtures();
        Notification::fake();

        $this->enableDrafting();
        $this->useOpenAi();
    }

    // ─── the call itself ─────────────────────────────────────────────────────

    /**
     * The schema reaches the provider as a schema.
     *
     * LlmProviderInterface::chat() used to take an untyped $opts array from
     * which every provider read exactly three keys and discarded the rest
     * without a word — which is how EmailAiController spent months passing two
     * hand-written system prompts as $opts['system'] that no model ever saw. A
     * response_format smuggled in the same way would have failed identically and
     * just as invisibly: the answer would still be JSON most of the time,
     * because the prompt asks for JSON.
     *
     * So this asserts on the wire, where "it was passed" and "it arrived" are
     * the same statement.
     */
    public function test_the_schema_and_the_system_prompt_reach_the_provider(): void
    {
        $item = $this->catalogueItem(['name' => 'Consult stomatologic']);

        $this->fakeOpenAi($this->answer([$this->chose($item)]));

        $this->deliver(self::PRICE_REQUEST);
        $this->runQueuedDrafts();

        $body = $this->lastOpenAiRequest();

        $this->assertSame(
            'json_schema',
            $body['response_format']['type'] ?? null,
            'the answer was not conditioned on a schema at all',
        );

        $schema = $body['response_format']['json_schema']['schema'] ?? [];
        $this->assertSame('object', $schema['type'] ?? null);

        // The three things the answer is actually made of.
        $this->assertArrayHasKey('interpretare', $schema['properties'] ?? []);
        $this->assertArrayHasKey('produse', $schema['properties'] ?? []);

        // And the schema does not ask for money. The one guarantee that cannot be
        // undone downstream is the one where the model was never invited to
        // produce a figure in the first place.
        $lineFields = $schema['properties']['produse']['items']['properties'] ?? [];
        $this->assertArrayHasKey('catalog_item_id', $lineFields);
        $this->assertArrayNotHasKey('pret', $lineFields);
        $this->assertArrayNotHasKey('unit_price_cents', $lineFields);
        $this->assertArrayNotHasKey('total', $lineFields);

        // The system instruction arrived too — the bug that started all of this.
        $system = collect($body['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';
        $this->assertNotSame('', trim((string) $system), 'the system prompt went nowhere, exactly as EmailAiController’s did');
    }

    /**
     * The drafter pins its own model.
     *
     * The provider settings screen offers gpt-3.5-turbo and claude-3-haiku, and
     * LlmManager::build() falls back to claude-3-haiku for any workspace that
     * never opened it. Reading a customer's message against a price list with
     * minimums, exclusions and bundles is not a job for the cheapest model in
     * the family, and a firm must not end up with worse drafts because of a
     * dropdown it set for its chatbot.
     */
    public function test_the_drafter_pins_a_model_rather_than_taking_the_one_set_for_the_chatbot(): void
    {
        $item = $this->catalogueItem();

        $this->fakeOpenAi($this->answer([$this->chose($item)]));

        $this->deliver(self::PRICE_REQUEST);
        $this->runQueuedDrafts();

        $this->assertNotSame(
            'gpt-4o-mini',
            $this->lastOpenAiRequest()['model'] ?? null,
            'the draft was built by whatever cheap model the firm happened to pick for its chatbot',
        );
    }

    /** The catalogue reaches the model with what the firm wrote about each item. */
    public function test_the_prompt_carries_the_catalogue_and_the_customers_own_words(): void
    {
        $item = $this->catalogueItem([
            'name' => 'Detartraj complet',
            'description' => 'Curățare profesională, o ședință.',
        ]);
        $this->tag($item, CatalogItemTag::KIND_FITS, 'adulti');
        $this->tag($item, CatalogItemTag::KIND_EXCLUDES, 'copii sub 12 ani');

        $this->fakeOpenAi($this->answer([$this->chose($item)]));

        $this->deliver('Cât costă un detartraj?');
        $this->runQueuedDrafts();

        $prompt = $this->lastPrompt();

        $this->assertStringContainsString('Detartraj complet', $prompt);
        $this->assertStringContainsString((string) $item->id, $prompt);
        $this->assertStringContainsString('copii sub 12 ani', $prompt, 'the firm’s red chip never reached the model');
        $this->assertStringContainsString('Cât costă un detartraj?', $prompt);
    }

    // ─── the model never touches money ───────────────────────────────────────

    /**
     * An id the model invented, and an id belonging to another firm.
     *
     * Both are dropped by the same check, and that is deliberate: the drafter
     * only ever knows the ids of the catalogue it loaded for THIS workspace, so
     * an id from anywhere else is not looked up at all. A model repeating an id
     * out of its own training must never be able to reach a competitor's price.
     */
    public function test_an_id_the_model_invented_never_becomes_a_line(): void
    {
        $mine = $this->catalogueItem(['name' => 'Consult stomatologic']);

        $other = $this->createWorkspaceContext();
        $theirs = $this->catalogueItem(
            ['name' => 'Produsul altei firme', 'price_cents' => 999900],
            (int) $other['workspace']->id,
        );

        $result = $this->draftWith([
            $this->chose($mine),
            $this->chose($theirs),
            ['catalog_item_id' => 99999999, 'cantitate' => 1, 'motiv' => 'Inventat.'],
        ]);

        $this->assertSame(
            [$mine->id],
            array_column($result['lines'], 'catalog_item_id'),
            'a proposal was accepted that this workspace’s catalogue never contained',
        );

        // And it is recorded, because "de ce nu e X în ofertă" is the first
        // question the person approving the draft will ask.
        $this->assertSame(
            [OfferDrafter::DROP_UNKNOWN_ITEM, OfferDrafter::DROP_UNKNOWN_ITEM],
            array_column($result['dropped'], 'key'),
        );

        foreach ($result['dropped'] as $note) {
            $this->assertStringNotContainsString('Produsul altei firme', (string) $note['name']);
            $this->assertStringNotContainsString('999900', (string) $note['detail']);
        }
    }

    /**
     * The firm's red chip is a hard constraint, not a hint to the model.
     *
     * "Nu îl propune dacă: copii" is the firm writing down that this product is
     * wrong for a customer who says they have children. The prompt says so, and
     * this is the net under the prompt for the times the model ignores it —
     * because the cost of getting it wrong is a clinic quoting a treatment it
     * has itself written down as unsuitable.
     */
    public function test_an_item_the_firm_excluded_for_what_the_customer_said_is_not_proposed(): void
    {
        $blocked = $this->catalogueItem(['name' => 'Albire cu lampă']);
        $this->tag($blocked, CatalogItemTag::KIND_EXCLUDES, 'aparat dentar');

        $fine = $this->catalogueItem(['name' => 'Consult ortodontic']);

        $result = $this->draftWith(
            [$this->chose($blocked), $this->chose($fine)],
            'Am aparat dentar, cât costă o albire?',
        );

        $this->assertSame(
            [$fine->id],
            array_column($result['lines'], 'catalog_item_id'),
            'the agent proposed exactly what the firm wrote down as wrong for this customer',
        );

        $dropped = collect($result['dropped'])->firstWhere('key', OfferDrafter::DROP_EXCLUDED);
        $this->assertNotNull($dropped, 'the refusal was not recorded, so nobody can see why the product is missing');
        $this->assertSame('Albire cu lampă', $dropped['name']);
        $this->assertSame('aparat dentar', $dropped['detail']);
    }

    /** Diacritics are optional on a phone; the net has to hold without them. */
    public function test_the_exclusion_net_holds_when_the_customer_writes_without_diacritics(): void
    {
        $blocked = $this->catalogueItem(['name' => 'Cremă pentru ten uscat']);
        $this->tag($blocked, CatalogItemTag::KIND_EXCLUDES, 'ten gras și sensibil');

        $result = $this->draftWith(
            [$this->chose($blocked)],
            'am ten gras si sensibil, cat costa o crema?',
        );

        $this->assertSame([], $result['lines']);
        $this->assertSame(OfferDrafter::REASON_NO_MATCH, $result['reason_key']);
    }

    /**
     * A bundle is a list of things, and it goes on the offer as that list.
     *
     * Stage 3's rule, and it is a customer-facing one rather than a data model
     * preference: a single row reading "Pachet complet — 800 lei" tells the
     * customer nothing about what they are buying, and the person approving it
     * cannot check the price against anything.
     */
    public function test_a_bundle_is_opened_up_into_its_components(): void
    {
        $consult = $this->catalogueItem(['name' => 'Consult', 'price_cents' => 15000, 'min_price_cents' => 15000]);
        $detartraj = $this->catalogueItem(['name' => 'Detartraj', 'price_cents' => 25000, 'min_price_cents' => 25000]);

        $bundle = $this->catalogueItem([
            'name' => 'Pachet igienizare',
            'type' => 'bundle',
            'price_cents' => 35000,
        ]);
        $this->bundleComponent($bundle, $consult, '1', 0);
        $this->bundleComponent($bundle, $detartraj, '2', 1);

        $result = $this->draftWith([$this->chose($bundle, 1)]);

        $this->assertSame(
            [$consult->id, $detartraj->id],
            array_column($result['lines'], 'catalog_item_id'),
            'the bundle went on as one opaque row, or did not go on at all',
        );

        // The bundle's own id is nowhere on the offer, and each line says which
        // package it came out of so the screen can group them.
        $this->assertNotContains($bundle->id, array_column($result['lines'], 'catalog_item_id'));
        $this->assertSame('Pachet igienizare', $result['lines'][0]['from_bundle']['name']);

        // The component quantity travels with it: two detartraje, not two packages.
        $this->assertSame(1.0, $result['lines'][0]['quantity']);
        $this->assertSame(2.0, $result['lines'][1]['quantity']);

        // And each line is priced from its own catalogue row, never from the
        // bundle's headline price.
        $this->assertSame(15000, $result['lines'][0]['unit_price_cents']);
        $this->assertSame(25000, $result['lines'][1]['unit_price_cents']);
    }

    /**
     * An out-of-stock line is FLAGGED, not silently quoted and not silently
     * dropped.
     *
     * Whether to quote a lead time or decline the enquiry is the firm's
     * commercial decision, so the line stays and the warning goes beside it.
     * What must never happen is the third option: a draft that reads as if the
     * product were on the shelf.
     */
    public function test_an_out_of_stock_line_is_flagged_rather_than_quietly_quoted(): void
    {
        $gone = $this->catalogueItem(['name' => 'Periuță electrică', 'stock' => 0]);

        $result = $this->draftWith([$this->chose($gone, 2)]);

        $line = $result['lines'][0];
        $this->assertSame($gone->id, $line['catalog_item_id']);
        $this->assertTrue($line['out_of_stock'], 'the line does not carry the flag the screen reads');

        $warning = collect($result['warnings'])->firstWhere('key', OfferDrafter::WARN_OUT_OF_STOCK);
        $this->assertNotNull($warning, 'nothing warned that the firm has none of this');
        $this->assertSame('Periuță electrică', $warning['name']);
    }

    /** A product with stock is not flagged, or the flag would mean nothing. */
    public function test_a_line_the_firm_has_in_stock_carries_no_warning(): void
    {
        $item = $this->catalogueItem(['stock' => 40]);

        $result = $this->draftWith([$this->chose($item, 2)]);

        $this->assertFalse($result['lines'][0]['out_of_stock']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * A price the model volunteered is clamped into the band the firm set.
     *
     * The schema never asks for a price — but "we did not ask for it" has never
     * stopped a model sending a field, and a discount below the floor is money
     * out of the firm's pocket on a document a customer can hold them to.
     *
     * The ceiling matters just as much and is easier to forget: a draft quoting
     * ABOVE the list price is a customer who checks the website and finds they
     * were being overcharged.
     */
    public function test_a_price_the_model_volunteered_is_clamped_into_the_firms_own_band(): void
    {
        $item = $this->catalogueItem([
            'name' => 'Consult',
            'price_cents' => 24000,
            'min_price_cents' => 20000,
        ]);

        // In lei, because lei is what the catalogue block speaks to the model.
        // 50 lei is well under the 200 lei floor the firm typed.
        $under = $this->draftWith([
            ['catalog_item_id' => $item->id, 'cantitate' => 1, 'motiv' => 'Ieftin.', 'pret' => 50],
        ]);

        $this->assertSame(
            20000,
            $under['lines'][0]['unit_price_cents'],
            'the agent quoted under the floor the firm typed',
        );

        $this->assertSame(
            [OfferDrafter::WARN_PRICE_CLAMPED],
            array_column($under['warnings'], 'key'),
            'the price was moved without telling the person approving the draft',
        );

        $over = $this->draftWith([
            ['catalog_item_id' => $item->id, 'cantitate' => 1, 'motiv' => 'Scump.', 'pret' => 990],
        ]);

        $this->assertSame(
            24000,
            $over['lines'][0]['unit_price_cents'],
            'the agent quoted above the firm’s own list price',
        );
    }

    /** With nothing volunteered, the catalogue price stands. */
    public function test_a_line_with_no_price_proposed_takes_the_catalogue_price(): void
    {
        $item = $this->catalogueItem(['price_cents' => 24000, 'min_price_cents' => 20000]);

        $result = $this->draftWith([$this->chose($item)]);

        $this->assertSame(24000, $result['lines'][0]['unit_price_cents']);
    }

    /**
     * An empty selection is an answer, not a malfunction.
     *
     * The two have to stay apart: one puts "nu am găsit nimic potrivit" on the
     * screen, the other is a bug worth waking somebody for.
     */
    public function test_a_model_that_finds_nothing_suitable_says_so_rather_than_inventing(): void
    {
        $this->catalogueItem();

        $result = $this->draftWith([]);

        $this->assertSame([], $result['lines']);
        $this->assertSame(OfferDrafter::REASON_NO_MATCH, $result['reason_key']);
    }

    // ─── the whole path, end to end ──────────────────────────────────────────

    /**
     * A burst of three messages produces ONE offer, built from all three.
     *
     * "One draft" on its own is not the requirement — a single draft built from
     * only the first message ("bună ziua") would satisfy it and be useless. The
     * customer's question is spread across the three, so the draft has to be
     * too.
     */
    public function test_three_messages_seconds_apart_produce_one_offer_built_from_all_three(): void
    {
        $item = $this->catalogueItem(['name' => 'Parchet stejar', 'unit' => 'mp', 'price_cents' => 12000, 'min_price_cents' => 10000]);

        $this->fakeOpenAi($this->answer([$this->chose($item, 40)]));

        $this->deliver('Bună ziua, aveți parchet stejar?');
        $this->deliver('Cât costă?');
        $this->deliver('Cam 40 mp, la parter.');

        $this->assertCount(1, $this->draftJobs(), 'a burst in one thread must queue one draft');

        $this->runQueuedDrafts();

        $offers = $this->aiOffers();
        $this->assertCount(1, $offers, 'a customer typing three lines got three offer numbers');

        // One provider call for the burst, not three. Three would be three bills
        // for one question.
        Http::assertSentCount(1);

        $prompt = $this->lastPrompt();
        $this->assertStringContainsString('aveți parchet stejar', $prompt);
        $this->assertStringContainsString('Cât costă', $prompt);
        $this->assertStringContainsString('40 mp', $prompt, 'the draft was built without the message carrying the quantity');
    }

    /**
     * What a drafted offer actually is: a real offer, with a real number, whose
     * money the server computed.
     */
    public function test_a_drafted_offer_is_an_ordinary_offer_the_firm_can_edit_and_send(): void
    {
        $item = $this->catalogueItem([
            'name' => 'Parchet stejar',
            'unit' => 'mp',
            'price_cents' => 12000,
            'min_price_cents' => 10000,
        ]);

        $this->fakeOpenAi($this->answer([$this->chose($item, 40)], 'Vă propun parchetul de stejar pentru cei 40 mp.'));

        $message = $this->deliver('Cât costă 40 mp de parchet stejar?');
        $this->runQueuedDrafts();

        $offer = $this->aiOffers()->firstOrFail();

        $this->assertSame('ai', $offer->source);
        $this->assertSame('draft', $offer->status);
        $this->assertSame($this->workspaceId(), (int) $offer->workspace_id);
        $this->assertSame((int) $this->thread->id, (int) $offer->conversation_id);
        $this->assertSame((int) $this->customer->id, (int) $offer->contact_id);
        $this->assertSame('OF-'.now()->format('Y').'-0001', $offer->number);

        // Nobody pressed a button, so nobody is the author. The badge on the
        // list reads `source`, not `created_by`.
        $this->assertNull($offer->created_by);

        // The lines are the catalogue's own words and the catalogue's own price.
        $line = OfferItem::where('offer_id', $offer->id)->firstOrFail();
        $this->assertSame($item->id, (int) $line->catalog_item_id);
        $this->assertSame('Parchet stejar', $line->name);
        $this->assertSame('mp', $line->unit);
        $this->assertSame('40.000', (string) $line->getAttribute('quantity'));
        $this->assertSame(12000, (int) $line->unit_price_cents);
        $this->assertSame(480000, (int) $line->line_total_cents);
        $this->assertSame('ai', $line->added_by);
        $this->assertSame($this->workspaceId(), (int) $line->workspace_id);

        // The total on the row is the server's arithmetic, not the model's.
        $this->assertSame(480000, (int) $offer->subtotal_cents);
        $this->assertSame(480000, (int) $offer->total_cents);

        // The reasoning is kept beside the offer for the left-hand column.
        $this->assertNotNull($offer->ai_json);
        $this->assertNotSame('', trim((string) $offer->ai_reason));

        // And the attempt closes out pointing at what it produced.
        $attempt = $this->attempts()->firstOrFail();
        $this->assertSame(OfferDraftAttempt::STATUS_DRAFTED, $attempt->status);
        $this->assertSame((int) $offer->id, (int) $attempt->offer_id);
        $this->assertSame((int) $message->id, (int) $attempt->message_id);
        $this->assertGreaterThan(0, (int) $attempt->tokens, 'what the draft cost was never recorded');
        $this->assertNull($attempt->reason);

        // Nothing at all went to the customer.
        $this->assertSame(0, $this->outboundOnThread());
    }

    /** A firm with an empty catalogue is told so before a token is spent. */
    public function test_a_firm_with_no_catalogue_is_not_billed_for_a_completion(): void
    {
        $this->deliver(self::PRICE_REQUEST);
        $this->runQueuedDrafts();

        $attempt = $this->attempts()->firstOrFail();
        $this->assertSame(OfferDraftAttempt::STATUS_FAILED, $attempt->status);
        $this->assertReasonIsTranslated((string) $attempt->reason);

        Http::assertNothingSent();
        $this->assertSame(0, Offer::count());
    }

    // ─── when it fails ───────────────────────────────────────────────────────

    /**
     * The provider refuses the key.
     *
     * Three things have to be true at once, and the third is the one this
     * codebase has got wrong before: the firm is told, in Romanian, on its own
     * screen; the customer is sent nothing; and the provider's own words — which
     * for a 401 are literally "Incorrect API key provided: sk-..." — stay in the
     * log.
     */
    public function test_a_provider_failure_is_recorded_in_romanian_and_the_customer_hears_nothing(): void
    {
        $this->catalogueItem();

        $this->fakeOpenAiRaw(fn () => Http::response([
            'error' => ['message' => 'Incorrect API key provided: sk-test-secret.', 'code' => 'invalid_api_key'],
        ], 401));

        $this->deliver(self::PRICE_REQUEST);
        $this->runQueuedDrafts();

        $attempt = $this->attempts()->firstOrFail();

        $this->assertSame(OfferDraftAttempt::STATUS_FAILED, $attempt->status);

        $reason = (string) $attempt->reason;
        $this->assertNotSame('', $reason);
        $this->assertLessThanOrEqual(64, strlen($reason), 'the reason column is string(64)');
        $this->assertReasonIsTranslated($reason);

        // The 401 body carries the API key back out. It may not be in the column
        // the offer screen renders.
        $this->assertStringNotContainsString('sk-test-secret', $reason);
        $this->assertStringNotContainsString('Incorrect API key', $reason);
        $this->assertStringNotContainsString('OpenAI', $reason);

        // No offer, no number burned out of the firm's series, and above all
        // nothing sent: ChatbotRunner would have replied to the customer here.
        $this->assertSame(0, Offer::count());
        $this->assertSame(0, $this->outboundOnThread());
        $this->assertSame(0, Message::where('conversation_id', $this->thread->id)->where('sent_by', 'bot')->count());
    }

    /** A workspace with no provider at all fails the same way, not with a 500. */
    public function test_a_workspace_with_no_provider_configured_is_recorded_the_same_way(): void
    {
        AiProviderConfig::query()->delete();
        $this->catalogueItem();

        $this->deliver(self::PRICE_REQUEST);
        $this->runQueuedDrafts();

        $attempt = $this->attempts()->firstOrFail();
        $this->assertSame(OfferDraftAttempt::STATUS_FAILED, $attempt->status);
        $this->assertReasonIsTranslated((string) $attempt->reason);
        $this->assertSame(0, Offer::count());
        $this->assertSame(0, $this->outboundOnThread());
    }

    /**
     * A cut-off answer is a failure, never half an offer.
     *
     * Nothing in this codebase used to notice a truncated completion, and JSON
     * is where that hurts: prose cut in half is visibly cut in half, but a JSON
     * object cut in half often decodes into a shorter, perfectly well-formed
     * one. A draft quoting three of the five things a customer asked for looks
     * exactly like a draft quoting everything they asked for.
     */
    public function test_an_answer_that_filled_its_whole_budget_is_treated_as_cut_off(): void
    {
        $item = $this->catalogueItem();

        // Well-formed, decodes cleanly, and used every token it was allowed —
        // which means it stopped because it ran out, not because it finished.
        $this->fakeOpenAiRaw(fn () => Http::response(
            $this->openAiBody($this->answer([$this->chose($item)]), completionTokens: 99999),
            200,
        ));

        $this->deliver(self::PRICE_REQUEST);
        $this->runQueuedDrafts();

        $attempt = $this->attempts()->firstOrFail();
        $this->assertSame(OfferDraftAttempt::STATUS_FAILED, $attempt->status);
        $this->assertReasonIsTranslated((string) $attempt->reason);
        $this->assertSame(0, Offer::count());

        // It asked again with a bigger budget before giving up — a truncation is
        // the one failure a retry can actually fix.
        $this->assertGreaterThanOrEqual(2, count($this->openAiRequests()));

        $budgets = array_column($this->openAiRequests(), 'max_tokens');
        $this->assertGreaterThan(
            (int) $budgets[0],
            (int) $budgets[1],
            'the retry asked for the same budget that had just run out',
        );
    }

    /** Prose where JSON was asked for is a failure, not something to guess at. */
    public function test_an_answer_that_is_not_json_is_recorded_rather_than_guessed_at(): void
    {
        $this->catalogueItem();

        $this->fakeOpenAi('Sigur! Vă pot ajuta cu o ofertă pentru clientul dumneavoastră.');

        $this->deliver(self::PRICE_REQUEST);
        $this->runQueuedDrafts();

        $attempt = $this->attempts()->firstOrFail();
        $this->assertSame(OfferDraftAttempt::STATUS_FAILED, $attempt->status);
        $this->assertReasonIsTranslated((string) $attempt->reason);
        $this->assertSame(0, Offer::count());
    }

    /**
     * The situation can change during the 45 seconds the job waits.
     *
     * A colleague opening the thread is the case that matters: the person is
     * already typing an answer, and a draft appearing behind them is the agent
     * getting in the way rather than helping.
     */
    public function test_a_colleague_taking_the_thread_during_the_debounce_cancels_the_draft(): void
    {
        $this->catalogueItem();
        $this->fakeOpenAi($this->answer([]));

        $this->deliver(self::PRICE_REQUEST);

        $this->thread->update(['assigned_to' => 'human']);

        $this->runQueuedDrafts();

        $attempt = $this->attempts()->firstOrFail();
        $this->assertSame(OfferDraftAttempt::STATUS_SKIPPED, $attempt->status);
        $this->assertReasonIsTranslated((string) $attempt->reason);

        // Skipped before the money is spent, not after.
        Http::assertNothingSent();
        $this->assertSame(0, Offer::count());
    }

    /** So does switching the agent off while a draft is in flight. */
    public function test_switching_the_agent_off_during_the_debounce_cancels_the_draft(): void
    {
        $this->catalogueItem();
        $this->fakeOpenAi($this->answer([]));

        $this->deliver(self::PRICE_REQUEST);

        $this->enableDrafting(false);

        $this->runQueuedDrafts();

        $this->assertSame(OfferDraftAttempt::STATUS_SKIPPED, $this->attempts()->firstOrFail()->status);
        Http::assertNothingSent();
        $this->assertSame(0, Offer::count());
    }

    // ─── correcting the reading, and regenerating from it ────────────────────

    /**
     * THE MOST IMPORTANT INTERACTION IN THE STAGE.
     *
     * The agent misreads "vopsea lavabilă" as "vopsea pentru lemn". A person
     * fixes one field and presses Regenerate. If the rebuild starts from the raw
     * message it will make the same mistake again, and the correction box is
     * decoration — a misreading stays a dead end instead of becoming a
     * five-second fix.
     */
    public function test_regenerating_starts_from_the_corrected_reading_and_not_the_raw_message(): void
    {
        $wrong = $this->catalogueItem(['name' => 'Vopsea pentru lemn', 'price_cents' => 8000, 'min_price_cents' => 8000]);
        $right = $this->catalogueItem(['name' => 'Vopsea lavabilă albă', 'price_cents' => 11000, 'min_price_cents' => 11000]);

        // The first draft, from the customer's own words — and it picks wrong.
        $this->fakeOpenAi($this->answer(
            [$this->chose($wrong, 3)],
            'Am ales vopseaua pentru lemn.',
            ['cere' => 'Vopsea pentru lemn', 'buget' => '', 'termen' => '', 'cerinte' => [], 'pentru' => ''],
        ));

        $this->deliver('Cât costă vopseaua? Vreau 3 bidoane.');
        $this->runQueuedDrafts();

        $offer = $this->aiOffers()->firstOrFail();
        $this->assertSame($wrong->id, (int) OfferItem::where('offer_id', $offer->id)->value('catalog_item_id'));

        // The person fixes what the agent understood.
        $this->actingAs($this->ctx['user'])
            ->put(route('client.offers.interpretation', $offer->uuid), [
                'cere' => 'Vopsea lavabilă albă pentru interior',
                'buget' => 'sub 400 lei',
                'termen' => 'până vineri',
                'pentru' => 'apartament',
                'cerinte' => ['lavabilă', 'albă'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // ...and presses Regenerate.
        $this->fakeOpenAi($this->answer(
            [$this->chose($right, 3)],
            'Am ales vopseaua lavabilă.',
            ['cere' => 'Vopsea lavabilă albă pentru interior', 'buget' => 'sub 400 lei', 'termen' => 'până vineri', 'cerinte' => ['lavabilă', 'albă'], 'pentru' => 'apartament'],
        ));

        $this->actingAs($this->ctx['user'])
            ->post(route('client.offers.regenerate', $offer->uuid))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            // Flashed separately from the validation bag, so it survives
            // assertSessionHasNoErrors and would otherwise turn a broken
            // regenerate into a confusing assertion three lines further down.
            ->assertSessionMissing('error');

        // THE ASSERTION THIS TEST EXISTS FOR: the corrected words went into the
        // prompt, so the rebuild started from the person's reading.
        $prompt = $this->lastPrompt();
        $this->assertStringContainsString('Vopsea lavabilă albă pentru interior', $prompt);
        $this->assertStringContainsString('sub 400 lei', $prompt);
        $this->assertStringContainsString('până vineri', $prompt);

        // And the draft that came back replaced the wrong line rather than
        // adding to it.
        $offer->refresh();
        $lines = OfferItem::where('offer_id', $offer->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame($right->id, (int) $lines[0]->catalog_item_id);
        $this->assertSame(33000, (int) $offer->total_cents);

        // The number is the firm's own commercial reference and may already have
        // been said on the phone. A regenerate must not burn a second one.
        $this->assertSame('OF-'.now()->format('Y').'-0001', $offer->number);
        $this->assertSame(1, Offer::count());

        // The corrected reading is what is stored now, so the NEXT regenerate
        // also starts from it.
        $this->assertSame(
            'Vopsea lavabilă albă pentru interior',
            $offer->ai_json['interpretation']['cere'] ?? null,
        );
    }

    /** Correcting the reading costs nothing — it is a save, not a provider call. */
    public function test_correcting_the_reading_does_not_spend_a_provider_call(): void
    {
        $item = $this->catalogueItem();
        $this->fakeOpenAi($this->answer([$this->chose($item)]));

        $this->deliver(self::PRICE_REQUEST);
        $this->runQueuedDrafts();

        $offer = $this->aiOffers()->firstOrFail();
        $before = count($this->openAiRequests());

        $this->actingAs($this->ctx['user'])
            ->put(route('client.offers.interpretation', $offer->uuid), ['cere' => 'Altceva cu totul'])
            ->assertRedirect();

        $this->assertCount($before, $this->openAiRequests(), 'every keystroke in the correction box billed the firm');
        $this->assertSame('Altceva cu totul', $offer->fresh()->ai_json['interpretation']['cere'] ?? null);
    }

    /** A hand-built offer has no agent reading to correct or rebuild. */
    public function test_a_hand_built_offer_cannot_be_regenerated(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.offers.store'), [])->assertRedirect();
        $offer = Offer::latest('id')->firstOrFail();
        $this->assertSame('human', $offer->source);

        $before = count($this->openAiRequests());

        // Refused either outright or with a message — what matters is that no
        // provider call is made and the offer is not touched.
        $this->actingAs($this->ctx['user'])
            ->post(route('client.offers.regenerate', $offer->uuid));

        $this->actingAs($this->ctx['user'])
            ->put(route('client.offers.interpretation', $offer->uuid), ['cere' => 'Orice']);

        $this->assertCount($before, $this->openAiRequests(), 'a hand-built offer spent a provider call');

        $offer->refresh();
        $this->assertSame('human', $offer->source);
        $this->assertNull($offer->ai_json, 'a hand-built offer was given an agent reading it never had');
        $this->assertNull($offer->ai_reason);
    }

    // ─── every reason a firm can be shown ────────────────────────────────────

    /**
     * Every reason this module can write is a key both locale files carry.
     *
     * The column holds a translation key precisely so a provider's words can
     * never reach a screen — but a key with no entry behind it reaches the
     * screen as the key itself, which for a Romanian firm is the same as being
     * told nothing at all.
     */
    public function test_every_reason_the_agent_can_write_is_translated_into_both_languages(): void
    {
        $keys = array_unique(array_merge(
            OfferDraftAttempt::REASONS,
            [
                OfferDrafter::REASON_NO_TEXT,
                OfferDrafter::REASON_NO_CATALOG,
                OfferDrafter::REASON_NO_PROVIDER,
                OfferDrafter::REASON_PROVIDER_FAILED,
                OfferDrafter::REASON_UNREADABLE,
                OfferDrafter::REASON_CUT_OFF,
                OfferDrafter::REASON_FAILED,
                OfferDrafter::REASON_NO_MATCH,
                OfferDrafter::DROP_UNKNOWN_ITEM,
                OfferDrafter::DROP_EXCLUDED,
                OfferDrafter::DROP_BUNDLE_INCOMPLETE,
                OfferDrafter::DROP_TOO_MANY,
                OfferDrafter::DROP_TOO_LARGE,
                OfferDrafter::WARN_OUT_OF_STOCK,
                OfferDrafter::WARN_PRICE_CLAMPED,
                OfferDrafter::WARN_QUANTITY_CLAMPED,
            ],
        ));

        foreach ($keys as $key) {
            $this->assertReasonIsTranslated($key);
        }
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * One entry of the model's "produse" array.
     *
     * @return array<string, mixed>
     */
    private function chose(CatalogItem $item, float $quantity = 1, string $why = 'Se potrivește cu ce a cerut clientul.'): array
    {
        return [
            'catalog_item_id' => $item->id,
            'cantitate' => $quantity,
            'motiv' => $why,
        ];
    }

    /**
     * A complete, well-formed answer in the shape the schema asks for.
     *
     * @param  list<array<string, mixed>>  $produse
     * @param  array<string, mixed>|null  $interpretare
     * @return array<string, mixed>
     */
    private function answer(array $produse, string $rezumat = 'Am ales din catalog.', ?array $interpretare = null): array
    {
        return [
            'interpretare' => $interpretare ?? [
                'cere' => 'Un preț pentru ce a întrebat.',
                'buget' => '',
                'termen' => '',
                'cerinte' => [],
                'pentru' => '',
            ],
            'produse' => $produse,
            'rezumat' => $rezumat,
        ];
    }

    /**
     * Run the drafter directly against one faked answer.
     *
     * Direct rather than through the queue for the hardening cases, because what
     * they are about is the drafter's own refusal list — `lines`, `dropped` and
     * `warnings` — and asserting on those through two more layers would only
     * make a failure harder to read.
     *
     * @param  list<array<string, mixed>>  $produse  what the model chose
     * @return array<string, mixed>
     */
    private function draftWith(array $produse, string $said = 'Cât costă?'): array
    {
        $this->fakeOpenAi($this->answer($produse));

        $message = Message::create([
            'conversation_id' => $this->thread->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $said,
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        return app(OfferDrafter::class)->draft(
            $this->thread->fresh(),
            new Collection([$message]),
        );
    }

    /**
     * The key really is a key, in both locale files, and the Romanian really is
     * Romanian.
     */
    private function assertReasonIsTranslated(string $key): void
    {
        $this->assertNotSame('', $key, 'a failed attempt carried no reason at all');

        $en = $this->translation('en', $key);
        $ro = $this->translation('ro', $key);

        $this->assertIsString($en, "resources/js/locales/en.json has no entry for {$key}");
        $this->assertIsString($ro, "resources/js/locales/ro.json has no entry for {$key}");
        $this->assertNotSame('', trim($ro));
        $this->assertNotSame($en, $ro, "{$key} is still the English string in ro.json");

        // ş and ţ with a cedilla are Turkish. Romanian takes the comma below.
        $this->assertStringNotContainsString("\u{015F}", $ro, "{$key} uses the Turkish ş");
        $this->assertStringNotContainsString("\u{0163}", $ro, "{$key} uses the Turkish ţ");
    }

    private function translation(string $locale, string $key): mixed
    {
        static $files = [];

        $files[$locale] ??= json_decode(
            (string) file_get_contents(resource_path("js/locales/{$locale}.json")),
            true,
        );

        $node = $files[$locale];

        foreach (explode('.', $key) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
