<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\Client;
use App\Models\ClientBusinessHour;
use App\Models\ClientProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFolder;
use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferDraftAttempt;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Offers\Models\OfferSeries;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Support\Romania;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies that one workspace cannot access another workspace's resources.
 */
class MultiTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithWorkspace(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);
        $user->refresh();

        return [$user, $workspace];
    }

    #[Test]
    public function workspace_a_cannot_see_workspace_b_contacts(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        Contact::factory()->create([
            'workspace_id' => $workspaceB->id,
            'first_name' => 'WorkspaceB',
            'last_name' => 'UniqueContactName',
            'phone_e164' => '+8801999999999',
        ]);

        // Compute the actual Inertia asset version from the Vite manifest
        $inertiaVersion = file_exists(public_path('build/manifest.json'))
            ? hash_file('xxh128', public_path('build/manifest.json'))
            : '';

        $response = $this->actingAs($userA)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $inertiaVersion])
            ->get('/app/contacts');

        $response->assertStatus(200);
        $response->assertJsonMissing(['first_name' => 'WorkspaceB']);
    }

    #[Test]
    public function workspace_a_cannot_delete_workspace_b_contact(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        $contactB = Contact::factory()->create([
            'workspace_id' => $workspaceB->id,
            'first_name' => 'B',
            'last_name' => 'Contact',
            'phone_e164' => '+8801888888888',
        ]);

        // Contact::getRouteKeyName() is 'uuid'. Addressing the route by the
        // integer primary key never binds, so the request 404s in the router and
        // the controller's ownership check — the thing under test — never runs.
        $response = $this->actingAs($userA)->delete("/app/contacts/{$contactB->uuid}");
        $response->assertStatus(403);
        $this->assertDatabaseHas('contacts', ['id' => $contactB->id]);
    }

    #[Test]
    public function workspace_a_cannot_edit_workspace_b_chatbot(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspaceB->id,
            'name' => 'B Chatbot',
        ]);

        $response = $this->actingAs($userA)->put("/app/ai/chatbots/{$chatbot->uuid}", ['name' => 'Hacked']);
        $response->assertStatus(403);
        $this->assertDatabaseHas('ai_chatbots', ['id' => $chatbot->id, 'name' => 'B Chatbot']);
    }

    /**
     * A client and one administrator of it.
     *
     * client_profiles and client_business_hours hang off client_id, not
     * workspace_id, so the boundary these tests exercise is the CLIENT rather
     * than the workspace — a firm with three workspaces still has one company
     * profile. Wraps the shared TestCase helper so the tenant is built exactly
     * the way every other feature test builds one.
     *
     * @return array{0: User, 1: Client}
     */
    private function createClientWithAdmin(string $name): array
    {
        $ctx = $this->createWorkspaceContext(['name' => $name], [
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'email_verified_at' => now(),
        ]);

        return [$ctx['user'], $ctx['client']];
    }

    /**
     * A complete, valid company form. Overrides let each test make its tenant's
     * values unmistakable, so an assertion cannot pass on a coincidence.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function companyPayload(array $overrides = []): array
    {
        return array_merge([
            'client_name' => 'Tenant SRL',
            'client_email' => 'office@tenant.ro',
            'client_phone' => '0264111222',
            'legal_name' => 'Tenant SRL',
            'industry' => 'dental_clinic',
            'company_size' => '2-10',
            'cui' => 'RO14399840',
            'vat_status' => 'standard',
            'vat_rate' => Romania::standardVatRate(),
            'address_street' => 'Str. Memorandumului 1',
            'address_city' => 'Cluj-Napoca',
            'address_county' => 'CJ',
            'address_postcode' => '400114',
            'address_country' => 'RO',
            'timezone' => 'Europe/Bucharest',
            'hours' => [
                ['day_of_week' => 1, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00'],
            ],
        ], $overrides);
    }

    /**
     * The Inertia asset version the client would send. A mismatch makes Inertia
     * answer 409 instead of rendering, so the props assertions below would never
     * run. Same trick as the contacts test above.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        $version = file_exists(public_path('build/manifest.json'))
            ? hash_file('xxh128', public_path('build/manifest.json'))
            : '';

        return ['X-Inertia' => 'true', 'X-Inertia-Version' => $version];
    }

    #[Test]
    public function client_a_saving_the_company_form_does_not_touch_client_b_profile(): void
    {
        [$adminA, $clientA] = $this->createClientWithAdmin('Alpha SRL');
        [, $clientB] = $this->createClientWithAdmin('Beta SRL');

        $profileB = ClientProfile::create([
            'client_id' => $clientB->id,
            'legal_name' => 'Beta Distributie SRL',
            'cui' => '98765438',
            'iban' => 'RO49AAAA1B31007593840000',
            'address_city' => 'Timisoara',
        ]);

        $response = $this->actingAs($adminA)->put('/app/settings/company', $this->companyPayload([
            'client_name' => 'Alpha SRL',
            'legal_name' => 'Alpha Medical SRL',
            'cui' => 'RO12345674',
            'address_city' => 'Cluj-Napoca',
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // A wrote its own row...
        $this->assertDatabaseHas('client_profiles', [
            'client_id' => $clientA->id,
            'legal_name' => 'Alpha Medical SRL',
            'cui' => '12345674',
        ]);

        // ...and B's is byte-for-byte what it was, not merely still present.
        $profileB->refresh();
        $this->assertSame('Beta Distributie SRL', $profileB->legal_name);
        $this->assertSame('98765438', $profileB->cui);
        $this->assertSame('RO49AAAA1B31007593840000', $profileB->iban);
        $this->assertSame('Timisoara', $profileB->address_city);
        $this->assertSame(1, ClientProfile::where('client_id', $clientB->id)->count());

        // The client row itself is a second write target on this endpoint.
        $this->assertDatabaseHas('clients', ['id' => $clientB->id, 'name' => 'Beta SRL']);
    }

    #[Test]
    public function client_a_company_page_never_carries_client_b_details(): void
    {
        [$adminA, $clientA] = $this->createClientWithAdmin('Alpha SRL');
        [, $clientB] = $this->createClientWithAdmin('Beta SRL');

        ClientProfile::create([
            'client_id' => $clientA->id,
            'legal_name' => 'Alpha Medical SRL',
            'cui' => '12345674',
        ]);
        ClientProfile::create([
            'client_id' => $clientB->id,
            'legal_name' => 'Beta Distributie SRL',
            'cui' => '98765438',
        ]);

        $response = $this->actingAs($adminA)
            ->withHeaders($this->inertiaHeaders())
            ->get('/app/settings/company');

        $response->assertStatus(200);
        $response->assertJsonPath('props.client.id', $clientA->id);
        $response->assertJsonPath('props.profile.cui', '12345674');
        $response->assertJsonPath('props.profile.legal_name', 'Alpha Medical SRL');

        // Nothing of B's anywhere in the payload — including the option lists and
        // any relation a later change might eager-load onto this page.
        $this->assertStringNotContainsString('98765438', $response->getContent());
        $this->assertStringNotContainsString('Beta Distributie SRL', $response->getContent());
    }

    #[Test]
    public function staff_of_a_client_cannot_read_the_company_form(): void
    {
        $ctx = $this->createWorkspaceContext([], [
            'client_role' => User::CLIENT_ROLE_STAFF,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($ctx['user'])
            ->withHeaders($this->inertiaHeaders())
            ->get('/app/settings/company')
            ->assertForbidden();
    }

    /**
     * The write is the one that matters: a gate applied only on the read side
     * hides the form while leaving the endpoint that changes the tenant's CUI,
     * IBAN and bank details open to every staff account.
     */
    #[Test]
    public function staff_of_a_client_cannot_save_the_company_form(): void
    {
        $ctx = $this->createWorkspaceContext(['name' => 'Alpha SRL'], [
            'client_role' => User::CLIENT_ROLE_STAFF,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($ctx['user'])
            ->put('/app/settings/company', $this->companyPayload(['client_name' => 'Hijacked SRL']))
            ->assertForbidden();

        $this->assertDatabaseHas('clients', ['id' => $ctx['client']->id, 'name' => 'Alpha SRL']);
        $this->assertDatabaseMissing('client_profiles', ['client_id' => $ctx['client']->id]);
    }

    #[Test]
    public function a_user_without_a_client_gets_no_company_data_at_all(): void
    {
        [, $clientB] = $this->createClientWithAdmin('Beta SRL');
        ClientProfile::create([
            'client_id' => $clientB->id,
            'legal_name' => 'Beta Distributie SRL',
            'cui' => '98765438',
        ]);

        $orphan = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => null,
            'client_role' => null,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $read = $this->actingAs($orphan)
            ->withHeaders($this->inertiaHeaders())
            ->get('/app/settings/company');

        $this->assertContains($read->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('98765438', $read->getContent());

        $write = $this->actingAs($orphan)->put('/app/settings/company', $this->companyPayload());
        $this->assertContains($write->getStatusCode(), [403, 404]);
        $this->assertDatabaseHas('client_profiles', ['client_id' => $clientB->id, 'cui' => '98765438']);
    }

    /**
     * Deleting a tenant must take its company profile and opening hours with it.
     * Both tables hold data a firm would expect gone — CUI, IBAN, bank name — and
     * neither model has a deleting hook, so the FK cascade is the only thing
     * doing it.
     */
    #[Test]
    public function deleting_a_client_cascades_its_profile_and_business_hours(): void
    {
        [, $clientA] = $this->createClientWithAdmin('Alpha SRL');
        [, $clientB] = $this->createClientWithAdmin('Beta SRL');

        foreach ([$clientA, $clientB] as $c) {
            ClientProfile::create(['client_id' => $c->id, 'legal_name' => $c->name]);
            ClientBusinessHour::create([
                'client_id' => $c->id,
                'day_of_week' => 1,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ]);
        }

        $clientA->delete();

        $this->assertDatabaseMissing('client_profiles', ['client_id' => $clientA->id]);
        $this->assertDatabaseMissing('client_business_hours', ['client_id' => $clientA->id]);

        // The cascade must stop at the tenant that was deleted.
        $this->assertDatabaseHas('client_profiles', ['client_id' => $clientB->id]);
        $this->assertDatabaseHas('client_business_hours', ['client_id' => $clientB->id]);
    }

    /**
     * The save path is delete-then-insert, not upsert: there is no
     * unique(client_id, day_of_week), because a clinic closed for lunch needs two
     * Monday rows. That makes accumulation the failure mode to prove absent —
     * ten saves must not leave ten Mondays, and must not touch another tenant's.
     */
    #[Test]
    public function saving_the_same_day_twice_replaces_the_interval_instead_of_duplicating_it(): void
    {
        [$adminA, $clientA] = $this->createClientWithAdmin('Alpha SRL');
        [, $clientB] = $this->createClientWithAdmin('Beta SRL');

        ClientBusinessHour::create([
            'client_id' => $clientB->id,
            'day_of_week' => 1,
            'opens_at' => '08:00',
            'closes_at' => '12:00',
        ]);

        $save = fn (string $opens, string $closes) => $this->actingAs($adminA)
            ->put('/app/settings/company', $this->companyPayload([
                'client_name' => 'Alpha SRL',
                'hours' => [
                    ['day_of_week' => 1, 'is_closed' => false, 'opens_at' => $opens, 'closes_at' => $closes],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $save('09:00', '17:00');
        $save('10:00', '18:00');

        $mondays = ClientBusinessHour::where('client_id', $clientA->id)->where('day_of_week', 1)->get();
        $this->assertCount(1, $mondays);
        $this->assertSame('10:00:00', $mondays->first()->opens_at);
        $this->assertSame('18:00:00', $mondays->first()->closes_at);

        // Two saves by A left B's Monday exactly as it was.
        $mondaysB = ClientBusinessHour::where('client_id', $clientB->id)->where('day_of_week', 1)->get();
        $this->assertCount(1, $mondaysB);
        $this->assertSame('08:00:00', $mondaysB->first()->opens_at);
    }

    /**
     * The document library, both of its tables. Every tenant-owned table has to
     * appear here — this is the file that says so, and a document is the kind of
     * thing where a leak is a contract.
     */
    #[Test]
    public function workspace_a_cannot_see_or_reach_workspace_b_documents(): void
    {
        [$userA] = $this->createUserWithWorkspace();
        [, $workspaceB] = $this->createUserWithWorkspace();

        $folderB = DocumentFolder::create(['workspace_id' => $workspaceB->id, 'name' => 'ContracteB']);
        $documentB = Document::create([
            'workspace_id' => $workspaceB->id,
            'folder_id' => $folderB->id,
            'name' => 'contract-secret-b.pdf',
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 2048,
        ]);

        $props = $this->actingAs($userA)->get('/app/documents')->viewData('page')['props'];

        $this->assertSame([], $props['documents']['data']);
        $this->assertSame([], $props['folders']);
        $this->assertSame(0, $props['storage']['used']['documents']);

        $this->actingAs($userA)->get(route('client.documents.file', $documentB->uuid))->assertForbidden();
        $this->actingAs($userA)->delete(route('client.documents.destroy', $documentB->uuid))->assertForbidden();
        $this->actingAs($userA)->delete(route('client.documents.folders.destroy', $folderB->id))->assertForbidden();

        $this->assertNotNull($documentB->fresh());
        $this->assertNotNull($folderB->fresh());
    }

    /**
     * The hand-written catalogue. Every tenant-owned table has to appear here,
     * and this one carries a firm's price list — the document a competitor would
     * most like to read.
     *
     * The derived numbers are asserted at zero alongside the empty list: a tile
     * that counts four items the firm cannot see has leaked the same fact a row
     * would have.
     */
    #[Test]
    public function workspace_a_cannot_see_or_reach_workspace_b_catalog_items(): void
    {
        [$userA] = $this->createUserWithWorkspace();
        [, $workspaceB] = $this->createUserWithWorkspace();

        $itemB = CatalogItem::create([
            'workspace_id' => $workspaceB->id,
            'type' => 'service',
            'name' => 'Detartraj complet B',
            'code' => 'DET-B',
            'category' => 'ServiciiB',
            'unit' => 'ședință',
            'price_cents' => 24000,
            'stock' => 1,
            'low_stock_threshold' => 5,
        ]);

        $props = $this->actingAs($userA)->get(route('client.catalog.index'))->viewData('page')['props'];

        $this->assertSame([], $props['items']['data']);
        $this->assertSame([], $props['categories']);

        $this->assertSame(0, $props['counts']['all']);
        $this->assertSame(0, $props['counts']['product']);
        $this->assertSame(0, $props['counts']['service']);
        $this->assertSame(0, $props['counts']['bundle']);

        $this->assertSame(0, $props['stats']['total']);
        $this->assertSame(0, $props['stats']['categories']);
        $this->assertSame(0, $props['stats']['low_stock']);
        $this->assertSame(0, $props['stats']['no_price']);

        // The inbox picker reads the same table over JSON, and it is the path
        // that would put another firm's price into a conversation.
        $this->actingAs($userA)
            ->getJson(route('client.catalog.search', ['q' => 'Detartraj']))
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($userA)->get(route('client.catalog.show', $itemB->uuid))->assertForbidden();
        $this->actingAs($userA)->put(route('client.catalog.update', $itemB->uuid), [
            'name' => 'Furat',
            'price' => '1',
        ])->assertForbidden();
        $this->actingAs($userA)->delete(route('client.catalog.destroy', $itemB->uuid))->assertForbidden();

        $itemB->refresh();
        $this->assertSame('Detartraj complet B', $itemB->name);
        $this->assertSame(24000, $itemB->price_cents);
        $this->assertNull($itemB->deleted_at);
    }

    /**
     * What the firm tells the agent about its catalogue: the description, the
     * green and red words, what goes with what, and what a bundle is made of.
     *
     * catalog_item_tags and catalog_item_links both carry their own
     * workspace_id, so both are asserted on their own terms rather than through
     * the item they hang off.
     *
     * The link table is the sharp end. Its two foreign keys point at
     * catalog_items without looking at workspace_id, so the database will store
     * a link to another firm's product the moment a controller lets one
     * through — and related_item_id is a raw integer arriving from a browser.
     */
    #[Test]
    public function workspace_a_cannot_see_or_reach_workspace_b_catalog_knowledge(): void
    {
        [$userA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        $partB = CatalogItem::create([
            'workspace_id' => $workspaceB->id,
            'type' => 'product',
            'name' => 'Centrala lor',
            'code' => 'CT-B',
            'unit' => 'buc',
            'price_cents' => 450000,
        ]);

        $bundleB = CatalogItem::create([
            'workspace_id' => $workspaceB->id,
            'type' => 'bundle',
            'name' => 'Ansamblul lor',
            'code' => 'ANS-B',
            'unit' => 'set',
            'price_cents' => 500000,
        ]);

        $this->actingAs($userB)->put(route('client.catalog.knowledge', $bundleB->uuid), [
            'description' => 'Ce spun ei despre produsul lor.',
            'min_price' => '4000',
            'fits' => ['clienții lor'],
            'excludes' => ['nu pentru alții'],
            'components' => [['related_item_id' => $partB->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, DB::table('catalog_item_tags')->where('workspace_id', $workspaceB->id)->count());
        $this->assertSame(1, DB::table('catalog_item_links')->where('workspace_id', $workspaceB->id)->count());

        // ── A's own screens know nothing about any of it ──────────────────
        $props = $this->actingAs($userA)->get(route('client.catalog.index'))->viewData('page')['props'];

        $this->assertSame(0, $props['counts']['bundle']);
        // The nudge banner is a count of A's own unfinished work. Counting B's
        // items would tell A how large B's catalogue is.
        $this->assertSame(0, $props['stats']['needs_help']);

        // ── and none of B's rows can be reached one at a time ─────────────
        $this->actingAs($userA)
            ->getJson(route('client.catalog.components', $bundleB->uuid))
            ->assertForbidden();

        $this->actingAs($userA)->put(route('client.catalog.knowledge', $bundleB->uuid), [
            'description' => 'Furat',
            'fits' => ['al meu acum'],
            'components' => [],
        ])->assertForbidden();

        Http::fake();

        $this->actingAs($userA)
            ->postJson(route('client.catalog.ai.describe'), ['item_ids' => [$partB->id, $bundleB->id]])
            ->assertForbidden();

        $this->actingAs($userA)->postJson(route('client.catalog.ai.apply'), [
            'proposals' => [['item_id' => $partB->id, 'description' => 'Furat', 'fits' => [], 'excludes' => []]],
        ])->assertForbidden();

        // Not one token spent on another firm's price list.
        Http::assertNothingSent();

        // ── nor pulled in through A's own item ────────────────────────────
        $itemA = CatalogItem::create([
            'workspace_id' => $userA->workspace_id,
            'type' => 'bundle',
            'name' => 'Ansamblul meu',
            'code' => 'ANS-A',
            'unit' => 'set',
            'price_cents' => 100000,
        ]);

        // The leak this whole table has to be defended against: a related_item_id
        // is a raw integer from a browser, and the foreign key alone would take it.
        $this->actingAs($userA)
            ->from(route('client.catalog.show', $itemA->uuid))
            ->put(route('client.catalog.knowledge', $itemA->uuid), [
                'components' => [['related_item_id' => $partB->id, 'quantity' => 1]],
                'links' => [['related_item_id' => $bundleB->id]],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, DB::table('catalog_item_links')->where('workspace_id', $userA->workspace_id)->count());
        $this->assertSame(0, DB::table('catalog_item_tags')->where('workspace_id', $userA->workspace_id)->count());

        // A's own item page shows A's own empty panel, and B's words nowhere.
        $showA = $this->actingAs($userA)->get(route('client.catalog.show', $itemA->uuid))->viewData('page')['props'];

        $this->assertSame([], $showA['item']['tags']);
        $this->assertSame([], $showA['item']['links']);
        $this->assertSame([], $showA['components']);
        $this->assertNull($showA['item']['description']);

        // ── and B still has everything it wrote ───────────────────────────
        $bundleB->refresh();
        $this->assertSame('Ce spun ei despre produsul lor.', $bundleB->description);
        $this->assertSame(400000, (int) $bundleB->min_price_cents);
        $this->assertSame(2, DB::table('catalog_item_tags')->where('workspace_id', $workspaceB->id)->count());
        $this->assertSame(1, DB::table('catalog_item_links')->where('workspace_id', $workspaceB->id)->count());
    }

    /**
     * Offers, their lines and their number series.
     *
     * Three tables in one case because they are one object: an offer that leaks
     * leaks its lines, and a number series that leaks tells a competitor how much
     * business the other firm is writing.
     *
     * offer_items carries its own workspace_id rather than being reached only
     * through its offer, so it is asserted on its own terms here.
     */
    #[Test]
    public function workspace_a_cannot_see_or_reach_workspace_b_offers(): void
    {
        [$userA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        $itemB = CatalogItem::create([
            'workspace_id' => $workspaceB->id,
            'type' => 'product',
            'name' => 'Produs B',
            'unit' => 'buc',
            'price_cents' => 50000,
        ]);

        $this->actingAs($userB)->post(route('client.offers.store'), []);
        $offerB = Offer::where('workspace_id', $workspaceB->id)->firstOrFail();
        $this->actingAs($userB)->put(route('client.offers.update', $offerB->uuid), [
            'items' => [['catalog_item_id' => $itemB->id, 'quantity' => 3]],
        ]);
        $offerB->refresh();

        $props = $this->actingAs($userA)->get(route('client.offers.index'))->viewData('page')['props'];

        $this->assertSame([], $props['offers']['data']);

        $this->assertSame(0, $props['counts']['all']);
        $this->assertSame(0, $props['counts']['draft']);
        $this->assertSame(0, $props['counts']['sent']);
        $this->assertSame(0, $props['counts']['accepted']);
        $this->assertSame(0, $props['counts']['refused']);

        // Every derived number, not just the list. The value in progress is the
        // one that would quietly tell A what B is quoting.
        $this->assertSame(0, $props['stats']['drafts']);
        $this->assertSame(0, $props['stats']['sent_this_month']);
        $this->assertSame(0, $props['stats']['accepted']);
        $this->assertSame(0, $props['stats']['accepted_rate']);
        $this->assertSame(0, $props['stats']['in_progress_cents']);
        $this->assertSame(0, $props['stats']['in_progress_count']);

        $this->actingAs($userA)->get(route('client.offers.show', $offerB->uuid))->assertForbidden();
        $this->actingAs($userA)->put(route('client.offers.update', $offerB->uuid), [
            'notes' => 'Furat',
        ])->assertForbidden();
        $this->actingAs($userA)->get(route('client.offers.pdf', $offerB->uuid))->assertForbidden();
        $this->actingAs($userA)->post(route('client.offers.decision', $offerB->uuid), [
            'decision' => 'refused',
        ])->assertForbidden();
        $this->actingAs($userA)->delete(route('client.offers.destroy', $offerB->uuid))->assertForbidden();

        // A's own numbering is untouched by B's — the series are separate rows.
        $this->actingAs($userA)->post(route('client.offers.store'), []);
        $offerA = Offer::where('workspace_id', $userA->workspace_id)->firstOrFail();
        $year = now()->format('Y');
        $this->assertSame("OF-{$year}-0001", $offerA->number);
        $this->assertSame("OF-{$year}-0001", $offerB->number);
        $this->assertSame(2, OfferSeries::count());

        $offerB->refresh();
        $this->assertNull($offerB->notes);
        $this->assertNull($offerB->decision);
        $this->assertNull($offerB->deleted_at);
        $this->assertSame(1, OfferItem::where('workspace_id', $workspaceB->id)->count());
        $this->assertSame(0, OfferItem::where('workspace_id', $userA->workspace_id)->count());
    }

    /**
     * The shop mirror, which this file has never covered. ecommerce_products and
     * ecommerce_stores are tenant-owned, the store row holds encrypted API
     * credentials, and the products screen shows three derived tiles.
     */
    #[Test]
    public function workspace_a_cannot_see_or_reach_workspace_b_shop_products(): void
    {
        [$userA] = $this->createUserWithWorkspace();
        [, $workspaceB] = $this->createUserWithWorkspace();

        $storeB = EcommerceStore::create([
            'workspace_id' => $workspaceB->id,
            'platform' => 'shopify',
            'name' => 'Magazinul B',
            'domain' => 'magazinul-b.myshopify.com',
            'status' => 'connected',
            'external_meta' => ['currency' => 'EUR'],
        ]);

        $productB = EcommerceProduct::create([
            'workspace_id' => $workspaceB->id,
            'store_id' => $storeB->id,
            'external_id' => 'ext-b-1',
            'platform' => 'shopify',
            'name' => 'Pantofi roșii B',
            'sku' => 'SKU-B-1',
            'price' => 19.99,
            'inventory_quantity' => 1,
            'status' => 'active',
        ]);

        $products = $this->actingAs($userA)
            ->get(route('client.ecommerce.products.index'))
            ->viewData('page')['props'];

        $this->assertSame([], $products['products']['data']);
        $this->assertSame([], $products['stores']);
        $this->assertSame(0, $products['stats']['total']);
        $this->assertSame(0, $products['stats']['low_stock']);
        $this->assertSame(0, $products['stats']['out_of_stock']);

        $this->actingAs($userA)
            ->getJson(route('client.ecommerce.products.search', ['q' => 'Pantofi']))
            ->assertOk()
            ->assertJsonCount(0);

        // The store list is where the domain and the sync state would surface.
        $storesPage = $this->actingAs($userA)->get(route('client.ecommerce.stores.index'));
        $this->assertSame([], $storesPage->viewData('page')['props']['stores']);
        $this->assertStringNotContainsString('magazinul-b.myshopify.com', $storesPage->getContent());

        $this->actingAs($userA)
            ->delete(route('client.ecommerce.stores.destroy', $storeB->uuid))
            ->assertForbidden();

        $this->assertDatabaseHas('ecommerce_products', ['id' => $productB->id, 'name' => 'Pantofi roșii B']);
        $this->assertDatabaseHas('ecommerce_stores', ['id' => $storeB->id, 'name' => 'Magazinul B']);
    }

    /**
     * The agent's own record of what it tried to draft.
     *
     * offer_draft_attempts is written on the inbound message path, by a listener
     * and not by a controller, so nothing about it goes through the request
     * scoping the rest of this file relies on. It carries its own workspace_id
     * for exactly that reason, and this case is what holds the column to it.
     *
     * What leaks if it is wrong is worse than a row count. The attempt is keyed
     * to a conversation and a message, and the offer it points at carries the
     * other firm's customer, their prices and the reasoning behind them — so a
     * badge counting the wrong workspace's drafts is a competitor being told how
     * much business is coming in, and a "Ciorne AI" tab that reads across
     * workspaces hands over the offers themselves.
     */
    #[Test]
    public function workspace_a_cannot_see_or_reach_workspace_b_offer_draft_attempts(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        $contactB = Contact::factory()->create([
            'workspace_id' => $workspaceB->id,
            'first_name' => 'Clientul',
            'last_name' => 'Lor',
        ]);

        $conversationB = Conversation::create([
            'workspace_id' => $workspaceB->id,
            'contact_id' => $contactB->id,
            'status' => 'open',
        ]);

        $messageB = Message::create([
            'conversation_id' => $conversationB->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Cât costă un tratament complet?',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        // B's agent drafted one offer and failed on another enquiry.
        $offerB = Offer::create([
            'workspace_id' => $workspaceB->id,
            'number' => 'OF-'.now()->format('Y').'-0007',
            'contact_id' => $contactB->id,
            'conversation_id' => $conversationB->id,
            'status' => 'draft',
            'source' => 'ai',
            'currency' => 'RON',
            'subtotal_cents' => 480000,
            'total_cents' => 480000,
            'ai_reason' => 'Secretul comercial al firmei B.',
        ]);

        $draftedB = OfferDraftAttempt::create([
            'workspace_id' => $workspaceB->id,
            'conversation_id' => $conversationB->id,
            'message_id' => $messageB->id,
            'status' => OfferDraftAttempt::STATUS_DRAFTED,
            'offer_id' => $offerB->id,
            'tokens' => 1234,
        ]);

        $failedB = OfferDraftAttempt::create([
            'workspace_id' => $workspaceB->id,
            'conversation_id' => $conversationB->id,
            'message_id' => $messageB->id + 1,
            'status' => OfferDraftAttempt::STATUS_FAILED,
            'reason' => OfferDraftAttempt::REASON_NO_MATCH,
            'tokens' => 300,
        ]);

        // ── The table itself is scoped by a column, not by a join ────────────
        $this->assertSame(2, OfferDraftAttempt::where('workspace_id', $workspaceB->id)->count());
        $this->assertSame(0, OfferDraftAttempt::where('workspace_id', $workspaceA->id)->count());

        // ── A's list screen ─────────────────────────────────────────────────
        $props = $this->actingAs($userA)->get(route('client.offers.index'))->viewData('page')['props'];

        $this->assertSame([], $props['offers']['data']);

        // Every count on the screen, the agent's own tab included. A non-zero
        // "Ciorne AI" is the banner telling A that N offers are waiting — for a
        // firm that has none.
        foreach ($props['counts'] as $tab => $count) {
            $this->assertSame(0, (int) $count, "counts.{$tab} carried another firm's offers");
        }

        foreach ($props['stats'] as $key => $value) {
            $this->assertSame(0, (int) $value, "stats.{$key} carried another firm's figures");
        }

        // The badge beside Oferte in the navigation rail is a shared Inertia
        // prop, so it is resolved outside the controller and has to be scoped
        // on its own.
        $this->assertSame(0, (int) $props['offerAiDraftsCount']);

        // And nothing of B's is anywhere in the payload, under any key.
        $encoded = (string) json_encode($props);
        $this->assertStringNotContainsString('Secretul comercial', $encoded);
        $this->assertStringNotContainsString($offerB->number, $encoded);
        $this->assertStringNotContainsString($offerB->uuid, $encoded);

        // ── A cannot reach the draft, or make B's agent spend money ──────────
        $this->actingAs($userA)->get(route('client.offers.show', $offerB->uuid))->assertForbidden();
        $this->actingAs($userA)->put(route('client.offers.interpretation', $offerB->uuid), [
            'cere' => 'Altceva',
        ])->assertForbidden();
        $this->actingAs($userA)->post(route('client.offers.regenerate', $offerB->uuid))->assertForbidden();

        // ── B still has everything it had ───────────────────────────────────
        $this->assertDatabaseHas('offer_draft_attempts', [
            'id' => $draftedB->id,
            'workspace_id' => $workspaceB->id,
            'status' => OfferDraftAttempt::STATUS_DRAFTED,
            'offer_id' => $offerB->id,
        ]);
        $this->assertDatabaseHas('offer_draft_attempts', [
            'id' => $failedB->id,
            'workspace_id' => $workspaceB->id,
            'status' => OfferDraftAttempt::STATUS_FAILED,
            'reason' => OfferDraftAttempt::REASON_NO_MATCH,
        ]);

        $offerB->refresh();
        $this->assertSame('Secretul comercial al firmei B.', $offerB->ai_reason);
        $this->assertSame('draft', $offerB->status);

        // B's own screen still counts its two drafts and its one waiting offer.
        $theirs = $this->actingAs($userB)->get(route('client.offers.index'))->viewData('page')['props'];
        $this->assertSame(1, (int) $theirs['counts']['ai_drafts']);
        $this->assertSame(1, (int) $theirs['offerAiDraftsCount']);
    }

    /**
     * The public offer link — the first unauthenticated route in the product
     * that serves a tenant's own data.
     *
     * It is in this file and not only in PublicOfferTest because tenancy here
     * has no session to lean on. There is no EnsureClientScope in front of it,
     * no workspace resolved from a user, and no middleware that will notice a
     * mistake: the only thing standing between one firm and another firm's
     * prices is that the uuid sits inside what the signature covers. So the case
     * asserted is the one that matters — a firm holding a perfectly valid link
     * to its own offer cannot turn it into a link to somebody else's.
     */
    #[Test]
    public function workspace_a_cannot_reach_workspace_b_offers_through_the_public_link(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        $offerA = $this->publicOffer($workspaceA->id, 'OF-A-0001', 'Consultație stomatologică');
        $offerB = $this->publicOffer($workspaceB->id, 'OF-B-0001', 'PREȚUL DE LISTĂ AL FIRMEI B');

        $linkA = URL::signedRoute('offers.public', ['offer' => $offerA->uuid], now()->addDays(7), absolute: false);

        // A's own link opens A's own offer, and nothing about it is unusual.
        $this->get($linkA)->assertOk()->assertSee($offerA->number);

        // The uuid swapped for B's. The signature covers the path, so this is
        // refused rather than answered with another firm's price list.
        $swapped = $this->get(str_replace($offerA->uuid, $offerB->uuid, $linkA));
        $swapped->assertForbidden();
        $swapped->assertDontSee('PREȚUL DE LISTĂ AL FIRMEI B');
        $swapped->assertDontSee($offerB->number);

        // Unsigned, and signed in as A, are both just as refused: this route has
        // no session and no notion of who is asking.
        $bareB = route('offers.public', ['offer' => $offerB->uuid], false);
        $this->get($bareB)->assertForbidden();
        $this->actingAs($userA)->get($bareB)->assertForbidden();

        // A's offer screen mints a link for A's offer only. Nothing on that page
        // can be pointed at B.
        $props = $this->actingAs($userA)
            ->get(route('client.offers.show', $offerA->uuid))
            ->viewData('page')['props'];

        $this->assertIsString($props['offer']['public_url']);
        $this->assertStringContainsString($offerA->uuid, $props['offer']['public_url']);
        $this->assertStringNotContainsString($offerB->uuid, (string) json_encode($props));

        // And B's own page carries nothing of A's, on the link B would send out.
        $linkB = URL::signedRoute('offers.public', ['offer' => $offerB->uuid], now()->addDays(7), absolute: false);
        $page = $this->get($linkB);
        $page->assertOk();
        $page->assertSee($offerB->number);
        $page->assertDontSee($offerA->number);
        $page->assertDontSee('Consultație stomatologică');
    }

    /** One sent offer with one line, ready to be opened by a guest. */
    private function publicOffer(int $workspaceId, string $number, string $lineName): Offer
    {
        $offer = Offer::create([
            'workspace_id' => $workspaceId,
            'number' => $number,
            'status' => 'sent',
            'source' => 'human',
            'currency' => 'RON',
            'subtotal_cents' => 24000,
            'total_cents' => 24000,
            'valid_until' => now()->addDays(14)->toDateString(),
            'sent_at' => now(),
        ]);

        OfferItem::create([
            'workspace_id' => $workspaceId,
            'offer_id' => $offer->id,
            'name' => $lineName,
            'unit' => 'buc',
            'quantity' => 1,
            'unit_price_cents' => 24000,
            'line_total_cents' => 24000,
            'position' => 0,
            'added_by' => 'human',
        ]);

        return $offer->fresh();
    }
}
