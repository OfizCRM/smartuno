<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\Admin\PlanController;
use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Catalog\Models\CatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Completează cu AI" — the amber banner's helper.
 *
 * Two requests, and the gap between them is the whole design: describe() drafts
 * and RETURNS, a person reads every sentence and ticks the ones they want, and
 * apply() writes only those. Nothing reaches the catalogue unconfirmed, because
 * a model that invents a warranty period into a price list is not a bug anybody
 * gets to fix after the quote has gone out.
 *
 * The other half of the design is that it fails loudly. ChatbotRunner swallows
 * every provider error into the bot's fallback reply and sends that to a real
 * customer, so a dead API key looks exactly like a normal answer. Here a failed
 * call has to come back as a translated Romanian message the modal can show,
 * with the provider's own words — which carry the API key back in the error
 * body — left in the log where they belong.
 */
class CatalogAiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{user: User, workspace: Workspace, client: Client} */
    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ctx = $this->createWorkspaceContext();

        AiProviderConfig::create([
            'workspace_id' => $this->workspaceId(),
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test-secret'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);
    }

    private function workspaceId(): int
    {
        return (int) $this->ctx['workspace']->id;
    }

    /** @param array<string, mixed> $attrs */
    private function item(array $attrs = [], ?int $workspaceId = null): CatalogItem
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
            'price_cents' => 12345,
            'is_active' => true,
        ], $attrs));
    }

    /**
     * A well-formed provider reply carrying these proposals.
     *
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array<string, mixed>
     */
    private function reply(array $proposals, int $completionTokens = 20): array
    {
        return $this->rawReply(
            (string) json_encode(['proposals' => $proposals], JSON_UNESCAPED_UNICODE),
            $completionTokens,
        );
    }

    /** @return array<string, mixed> */
    private function rawReply(string $content, int $completionTokens = 20): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => $completionTokens],
            'model' => 'gpt-4o-mini',
        ];
    }

    /** @param array<string, mixed> $body */
    private function fakeChat(array $body, int $status = 200): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response($body, $status)]);
    }

    /** @param array<int, int> $ids */
    private function describe(array $ids): TestResponse
    {
        return $this->actingAs($this->ctx['user'])
            ->postJson(route('client.catalog.ai.describe'), ['item_ids' => $ids]);
    }

    /** @param array<int, array<string, mixed>> $proposals */
    private function apply(array $proposals): TestResponse
    {
        return $this->actingAs($this->ctx['user'])
            ->postJson(route('client.catalog.ai.apply'), ['proposals' => $proposals]);
    }

    /** Nothing at all has been written to the catalogue. */
    private function assertCatalogueUntouched(): void
    {
        $this->assertSame(
            0,
            CatalogItem::whereNotNull('description')->count(),
            'a draft is not a save: no description may reach catalog_items before a person confirms it',
        );
        $this->assertSame(0, DB::table('catalog_item_tags')->count());
        $this->assertSame(0, CatalogItem::whereNotNull('description_source')->count());
    }

    /**
     * The message on the screen is one a Romanian firm can read.
     *
     * lang/ro.json is hand-authored and keyed by the English string, so a
     * message with no entry there reaches the modal in English — which for this
     * customer is the same as no message at all.
     */
    private function assertRomanianMessage(string $message): void
    {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(base_path('lang/ro.json')), true);

        $this->assertIsArray($translations);
        $this->assertArrayHasKey($message, $translations, "lang/ro.json has no Romanian for: {$message}");

        $romanian = (string) $translations[$message];
        $this->assertNotSame($message, $romanian);

        // ş and ţ with a cedilla are Turkish. Romanian takes the comma below.
        $this->assertStringNotContainsString("\u{015F}", $romanian);
        $this->assertStringNotContainsString("\u{0163}", $romanian);
    }

    // ─── the draft ───────────────────────────────────────────────────────

    public function test_describe_returns_proposals_and_writes_nothing(): void
    {
        $first = $this->item(['name' => 'Adeziv gresie 25kg', 'code' => 'AD-25']);
        $second = $this->item(['name' => 'Bandă izolatoare', 'code' => 'BI-1']);

        $this->fakeChat($this->reply([
            [
                'item_id' => $first->id,
                'name' => 'CE VREA MODELUL',
                'description' => 'Îl folosiți la lipirea plăcilor ceramice pe pardoseală.',
                'fits' => ['băi și bucătării'],
                'excludes' => ['pereți exteriori'],
            ],
            [
                'item_id' => $second->id,
                'description' => 'O folosiți pentru izolarea conexiunilor electrice.',
                'fits' => ['lucrări de electrică'],
                'excludes' => [],
            ],
        ]));

        $response = $this->describe([$first->id, $second->id]);

        $response->assertOk();

        $proposals = $response->json('proposals');
        $this->assertCount(2, $proposals);

        $keys = array_keys($proposals[0]);
        sort($keys);
        $this->assertSame(['description', 'excludes', 'fits', 'item_id', 'name'], $keys);

        $this->assertSame($first->id, $proposals[0]['item_id']);
        // The name is ours. The model is being asked to describe this product,
        // not to rename it, and the modal shows the person the row they ticked.
        $this->assertSame('Adeziv gresie 25kg', $proposals[0]['name']);
        $this->assertSame('Îl folosiți la lipirea plăcilor ceramice pe pardoseală.', $proposals[0]['description']);
        $this->assertSame(['băi și bucătării'], $proposals[0]['fits']);
        $this->assertSame(['pereți exteriori'], $proposals[0]['excludes']);
        $this->assertSame([], $proposals[1]['excludes']);

        // One call for the whole batch — the cap exists because the batch is one
        // answer, and ten separate calls is ten times the money.
        Http::assertSentCount(1);

        $this->assertCatalogueUntouched();
    }

    public function test_the_prompt_carries_what_the_catalogue_knows_and_no_more(): void
    {
        $item = $this->item([
            'name' => 'Adeziv gresie 25kg',
            'code' => 'AD-25',
            'category' => 'Adezivi',
            'unit' => 'sac',
            'price_cents' => 4599,
        ]);

        $sent = null;

        Http::fake(['api.openai.com/v1/chat/completions' => function ($request) use (&$sent) {
            $sent = json_decode($request->body(), true);

            return Http::response($this->reply([
                ['item_id' => $this->itemIdFrom($sent), 'description' => 'Text.', 'fits' => [], 'excludes' => []],
            ]));
        }]);

        $this->describe([$item->id])->assertOk();

        $this->assertIsArray($sent);

        $system = collect($sent['messages'])->firstWhere('role', 'system')['content'];
        $user = collect($sent['messages'])->firstWhere('role', 'user')['content'];

        // Romanian, addressed to the customer, and told to say nothing it cannot
        // know. All three are the reason this is safe to show a firm at all.
        $this->assertStringContainsString('Romanian', $system);
        $this->assertMatchesRegularExpression('/second person plural|dumneavoastr|"vă"/iu', $system);
        $this->assertMatchesRegularExpression('/invent|cannot know|not enough to say/i', $system);

        // The four fields the item actually has, and nothing else exists yet.
        $this->assertStringContainsString('Adeziv gresie 25kg', $user);
        $this->assertStringContainsString('AD-25', $user);
        $this->assertStringContainsString('Adezivi', $user);
        $this->assertStringContainsString('sac', $user);

        // Not the price. It is not needed to describe the thing, and a
        // description that quotes a price is a description that goes stale the
        // next time the firm changes one.
        $this->assertStringNotContainsString('4599', $user);
        $this->assertStringNotContainsString('45,99', $user);
    }

    /** @param array<string, mixed>|null $sent */
    private function itemIdFrom(?array $sent): int
    {
        $user = collect($sent['messages'] ?? [])->firstWhere('role', 'user')['content'] ?? '';
        preg_match('/"id":\s*(\d+)/', (string) $user, $m);

        return (int) ($m[1] ?? 0);
    }

    // ─── the save ────────────────────────────────────────────────────────

    public function test_apply_writes_only_the_ones_that_were_confirmed(): void
    {
        $confirmed = $this->item(['name' => 'Adeziv gresie 25kg']);
        $skipped = $this->item(['name' => 'Bandă izolatoare']);

        Http::fake();

        $this->apply([[
            'item_id' => $confirmed->id,
            'description' => 'Îl folosiți la lipirea plăcilor ceramice.',
            'fits' => ['băi și bucătării'],
            'excludes' => ['pereți exteriori'],
        ]])->assertOk();

        $confirmed->refresh();
        $this->assertSame('Îl folosiți la lipirea plăcilor ceramice.', $confirmed->description);
        // Stamped, so a later stage can tell the firm which of its knowledge it
        // wrote itself — and so this item stops being counted by the banner.
        $this->assertSame('ai', $confirmed->description_source);

        $tags = DB::table('catalog_item_tags')->where('catalog_item_id', $confirmed->id)->get();
        $this->assertCount(2, $tags);

        foreach ($tags as $tag) {
            $this->assertSame('ai', $tag->source);
            $this->assertSame($this->workspaceId(), (int) $tag->workspace_id);
        }

        $this->assertSame(
            ['fits' => 'băi și bucătării', 'excludes' => 'pereți exteriori'],
            $tags->pluck('label', 'kind')->all(),
        );

        // The one the person unticked is untouched — apply() writes the payload
        // it was given and never the batch that was drafted.
        $skipped->refresh();
        $this->assertNull($skipped->description);
        $this->assertNull($skipped->description_source);
        $this->assertSame(0, DB::table('catalog_item_tags')->where('catalog_item_id', $skipped->id)->count());

        // Confirming costs nothing: the drafting call already happened.
        Http::assertNothingSent();
    }

    public function test_apply_cleans_the_values_it_is_handed_back(): void
    {
        $item = $this->item();

        Http::fake();

        $this->apply([[
            'item_id' => $item->id,
            // Cedilla forms, as models emit them. Romanian takes the comma below,
            // and the two are indistinguishable in most UI fonts.
            'description' => 'Se foloseşte în construcţii.',
            'fits' => ['băi', 'BĂI', str_repeat('ă', 120)],
            // The same words on both sides: the positive reading has to win, or a
            // tag the model was unsure about silently blocks a sale.
            'excludes' => ['băi'],
        ]])->assertOk();

        $item->refresh();

        $this->assertStringNotContainsString("\u{015F}", (string) $item->description);
        $this->assertStringNotContainsString("\u{0163}", (string) $item->description);
        $this->assertSame('Se folosește în construcții.', $item->description);

        $labels = DB::table('catalog_item_tags')->where('catalog_item_id', $item->id)->pluck('label', 'kind')->all();

        // 'BĂI' is the same word as 'băi' to a utf8mb4_unicode_ci unique key, so
        // letting both through is a duplicate-key 500, not a duplicate chip.
        $this->assertSame(2, DB::table('catalog_item_tags')->where('catalog_item_id', $item->id)->where('kind', 'fits')->count());
        $this->assertSame(0, DB::table('catalog_item_tags')->where('catalog_item_id', $item->id)->where('kind', 'excludes')->count());
        $this->assertArrayHasKey('fits', $labels);

        $longest = DB::table('catalog_item_tags')
            ->where('catalog_item_id', $item->id)
            ->orderByRaw('CHAR_LENGTH(label) DESC')
            ->value('label');

        // label is string(64). Longer would be cut by MySQL, or refused by it.
        $this->assertLessThanOrEqual(64, mb_strlen((string) $longest));
    }

    public function test_apply_keeps_a_word_the_person_typed_themselves(): void
    {
        $item = $this->item();

        DB::table('catalog_item_tags')->insert([
            'workspace_id' => $this->workspaceId(),
            'catalog_item_id' => $item->id,
            'kind' => 'fits',
            'label' => 'băi și bucătării',
            'source' => 'human',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake();

        $this->apply([[
            'item_id' => $item->id,
            'description' => 'O descriere.',
            'fits' => ['băi și bucătării'],
            'excludes' => [],
        ]])->assertOk();

        $rows = DB::table('catalog_item_tags')->where('catalog_item_id', $item->id)->get();

        $this->assertCount(1, $rows);
        // Still theirs. Re-stamping it 'ai' would lose the only record of who is
        // responsible for the words on the screen.
        $this->assertSame('human', $rows[0]->source);
    }

    // ─── when the provider does not answer ───────────────────────────────

    public function test_a_provider_failure_is_reported_and_nothing_is_written(): void
    {
        $item = $this->item();

        // What OpenAI actually returns for a bad key — the key included.
        $this->fakeChat([
            'error' => ['message' => 'Incorrect API key provided: sk-test-secret.', 'code' => 'invalid_api_key'],
        ], 401);

        $response = $this->describe([$item->id]);

        $this->assertSame(422, $response->status(), 'a failed call must be an answer the modal can show, not a 500');

        $message = (string) $response->json('message');
        $this->assertNotSame('', $message);
        $this->assertRomanianMessage($message);

        // The provider's own words carry the API key back out. They belong in
        // the log, never in a browser.
        $this->assertStringNotContainsString('sk-test-secret', $message);
        $this->assertStringNotContainsString('Incorrect API key', $message);
        $this->assertStringNotContainsString('OpenAI', $message);

        $this->assertCatalogueUntouched();
    }

    public function test_a_workspace_with_no_provider_configured_is_told_so(): void
    {
        AiProviderConfig::query()->delete();

        $item = $this->item();

        Http::fake();

        $response = $this->describe([$item->id]);

        $this->assertSame(422, $response->status());
        $this->assertRomanianMessage((string) $response->json('message'));
        // Not a stack trace, and not a fallback sentence pretending to be an
        // answer: nothing was asked, so nothing may look as if it was answered.
        $this->assertCatalogueUntouched();
    }

    public function test_an_answer_that_is_not_json_is_reported_rather_than_guessed_at(): void
    {
        $item = $this->item();

        $this->fakeChat($this->rawReply('Sigur! Vă pot ajuta cu descrierea produselor dumneavoastră.'));

        $response = $this->describe([$item->id]);

        $this->assertSame(422, $response->status());
        $this->assertRomanianMessage((string) $response->json('message'));
        $this->assertCatalogueUntouched();
    }

    public function test_a_truncated_answer_is_reported_rather_than_half_applied(): void
    {
        $first = $this->item(['name' => 'Adeziv gresie 25kg']);
        $second = $this->item(['name' => 'Bandă izolatoare']);

        // The completion stopped mid-string. json_decode fails outright, and the
        // salvage pass cannot find a closing brace either.
        $this->fakeChat($this->rawReply(
            '{"proposals":[{"item_id":'.$first->id.',"description":"Îl folosiți la lipirea plăcilor cer'
        ));

        $response = $this->describe([$first->id, $second->id]);

        $this->assertSame(422, $response->status());
        $this->assertRomanianMessage((string) $response->json('message'));
        $this->assertCatalogueUntouched();
    }

    public function test_an_answer_that_fills_the_whole_budget_is_treated_as_cut_off(): void
    {
        $item = $this->item();

        // 500 + 300 per item. A completion that used every token it was allowed
        // stopped because it ran out, not because it finished — and the JSON it
        // did produce may decode perfectly while describing half the batch.
        $this->fakeChat($this->reply([
            ['item_id' => $item->id, 'description' => 'Text.', 'fits' => [], 'excludes' => []],
        ], completionTokens: 800));

        $response = $this->describe([$item->id]);

        $this->assertSame(422, $response->status());
        $this->assertRomanianMessage((string) $response->json('message'));
        $this->assertCatalogueUntouched();
    }

    public function test_an_answer_that_skips_a_product_is_refused_for_the_whole_batch(): void
    {
        $first = $this->item(['name' => 'Adeziv gresie 25kg']);
        $second = $this->item(['name' => 'Bandă izolatoare']);

        $this->fakeChat($this->reply([
            ['item_id' => $first->id, 'description' => 'Text.', 'fits' => [], 'excludes' => []],
        ]));

        $response = $this->describe([$first->id, $second->id]);

        // Half a batch presented as a whole one is the failure the person cannot
        // see: they tick four rows and two of them were never prepared.
        $this->assertSame(422, $response->status());
        $this->assertNotSame('', (string) $response->json('message'));
        $this->assertCatalogueUntouched();
    }

    public function test_an_id_the_model_invented_never_reaches_the_screen(): void
    {
        $item = $this->item(['name' => 'Adeziv gresie 25kg']);

        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Produsul lor'], (int) $intruder['workspace']->id);

        $this->fakeChat($this->reply([
            ['item_id' => $item->id, 'description' => 'Text.', 'fits' => [], 'excludes' => []],
            ['item_id' => $theirs->id, 'description' => 'Furat.', 'fits' => ['orice'], 'excludes' => []],
        ]));

        $proposals = $this->describe([$item->id])->assertOk()->json('proposals');

        // The batch was scoped before the call, so an id that was not in it is
        // either a hallucination or another firm's product. Neither is looked up.
        $this->assertCount(1, $proposals);
        $this->assertSame($item->id, $proposals[0]['item_id']);
    }

    // ─── whose products may be described ─────────────────────────────────

    public function test_another_firms_item_cannot_be_described(): void
    {
        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Secretul lor'], (int) $intruder['workspace']->id);

        Http::fake();

        $this->describe([$theirs->id])->assertForbidden();

        // Refused before a token is spent. Answering would return their product
        // name in the proposal, and paying to find out which ids exist is a probe
        // somebody else's card settles.
        Http::assertNothingSent();
        $this->assertCatalogueUntouched();
    }

    public function test_a_batch_that_mixes_in_another_firms_item_is_refused_whole(): void
    {
        $mine = $this->item(['name' => 'Al meu']);

        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Al lor'], (int) $intruder['workspace']->id);

        Http::fake();

        $this->describe([$mine->id, $theirs->id])->assertForbidden();

        Http::assertNothingSent();
        $this->assertCatalogueUntouched();
    }

    public function test_another_firms_item_cannot_be_written_by_apply(): void
    {
        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Secretul lor'], (int) $intruder['workspace']->id);

        // The payload comes from the browser. That it originally came from us is
        // not a reason to trust the round trip.
        $this->apply([[
            'item_id' => $theirs->id,
            'description' => 'Furat.',
            'fits' => ['orice'],
            'excludes' => [],
        ]])->assertForbidden();

        $theirs->refresh();
        $this->assertNull($theirs->description);
        $this->assertSame(0, DB::table('catalog_item_tags')->count());
    }

    public function test_a_batch_apply_that_mixes_in_another_firms_item_writes_neither(): void
    {
        $mine = $this->item(['name' => 'Al meu']);

        $intruder = $this->createWorkspaceContext();
        $theirs = $this->item(['name' => 'Al lor'], (int) $intruder['workspace']->id);

        $this->apply([
            ['item_id' => $mine->id, 'description' => 'O descriere.', 'fits' => [], 'excludes' => []],
            ['item_id' => $theirs->id, 'description' => 'Furat.', 'fits' => [], 'excludes' => []],
        ])->assertForbidden();

        // Not "the good ones went through": a partial write on a refused request
        // is how a cross-tenant probe gets to look like it worked.
        $this->assertNull($mine->fresh()->description);
        $this->assertNull($theirs->fresh()->description);
    }

    public function test_more_than_ten_products_in_one_go_is_refused(): void
    {
        $ids = [];
        for ($i = 0; $i < 11; $i++) {
            $ids[] = $this->item()->id;
        }

        Http::fake();

        $this->describe($ids)->assertStatus(422)->assertJsonValidationErrors('item_ids');

        // The cap is a cap on money as much as on quality: refused before the
        // call, not after it.
        Http::assertNothingSent();
        $this->assertCatalogueUntouched();
    }

    public function test_an_empty_batch_is_refused(): void
    {
        Http::fake();

        $this->describe([])->assertStatus(422)->assertJsonValidationErrors('item_ids');

        Http::assertNothingSent();
    }

    // ─── what the money costs ────────────────────────────────────────────

    /**
     * The two platform fixes this stage paid for, seen from the outside.
     *
     * UsageMeter::track() used to reset the row to zero before incrementing it,
     * so the meter read "the last call" and never a month's total — a plan that
     * capped AI tokens could never have caught anything. And ai_runs had no
     * workspace_id at all, so the spend could be counted but never attributed:
     * with one bill and several thousand firms on it, that is the difference
     * between knowing who spent it and not.
     */
    public function test_every_call_is_metered_and_attributed_to_the_firm_that_made_it(): void
    {
        $item = $this->item();

        $this->fakeChat($this->reply([
            ['item_id' => $item->id, 'description' => 'Text.', 'fits' => [], 'excludes' => []],
        ]));

        $this->describe([$item->id])->assertOk();
        $this->describe([$item->id])->assertOk();

        // 50 + 20 per call, twice. Not 70: a meter that reads the last call is
        // not a meter.
        $this->assertSame(
            140,
            (int) DB::table('usage_meters')
                ->where('workspace_id', $this->workspaceId())
                ->where('metric', 'ai_tokens')
                ->value('value'),
        );

        $runs = DB::table('ai_runs')->get();

        $this->assertCount(2, $runs);

        foreach ($runs as $run) {
            $this->assertSame(
                $this->workspaceId(),
                (int) $run->workspace_id,
                'an ai_runs row with no workspace is spend nobody can attribute',
            );
        }
    }

    /**
     * The blast radius of fixing the meter.
     *
     * EnforceLimit is on this route, and the meter it reads now accumulates
     * where it used to be reset to the last call. That could only start blocking
     * somebody if a plan carried an ai_tokens_per_month limit — and the plan form
     * writes only `users` and `storage` (PlanController::defaultLimits), while
     * EnforceLimit reads a missing key as null and null as unlimited.
     *
     * So: a firm on a real plan keeps working, and its usage still adds up.
     */
    public function test_a_plan_that_says_nothing_about_ai_tokens_blocks_nobody(): void
    {
        $this->assertSame(['users', 'storage'], array_keys(PlanController::defaultLimits()));

        $plan = Plan::factory()->create(['limits' => PlanController::defaultLimits()]);
        $this->attachPlanToClient($this->ctx['client'], $plan);

        $item = $this->item();

        $this->fakeChat($this->reply([
            ['item_id' => $item->id, 'description' => 'Text.', 'fits' => [], 'excludes' => []],
        ]));

        $this->describe([$item->id])->assertOk();
        $this->describe([$item->id])->assertOk();

        $this->assertSame(
            140,
            (int) DB::table('usage_meters')
                ->where('workspace_id', $this->workspaceId())
                ->where('metric', 'ai_tokens')
                ->value('value'),
        );
    }

    /**
     * And the other half of the same fix: a plan that DOES cap AI tokens can now
     * enforce it. Before the meter accumulated, the reading was always the last
     * call — 70 against a cap of 100, for ever — so the cap could never be
     * reached no matter how much a firm spent.
     */
    public function test_a_plan_that_caps_ai_tokens_stops_at_the_cap(): void
    {
        $plan = Plan::factory()->create(['limits' => ['ai_tokens_per_month' => 100]]);
        $this->attachPlanToClient($this->ctx['client'], $plan);

        $item = $this->item();

        $this->fakeChat($this->reply([
            ['item_id' => $item->id, 'description' => 'Text.', 'fits' => [], 'excludes' => []],
        ]));

        // The meter is read before the call, so it is the reading left behind by
        // the calls already made: 0, then 70, then 140 — and 140 is over the cap.
        $this->describe([$item->id])->assertOk();
        $this->describe([$item->id])->assertOk();

        $this->describe([$item->id])->assertStatus(402);

        // Blocked before the provider, which is the only kind of cap that saves
        // anybody any money. With the meter reading "the last call" this third
        // request read 70 against a cap of 100 and went through, and so would
        // every one after it.
        Http::assertSentCount(2);
    }
}
