<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\Client;
use App\Models\ClientBusinessHour;
use App\Models\ClientProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\Contact;
use App\Support\Romania;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
