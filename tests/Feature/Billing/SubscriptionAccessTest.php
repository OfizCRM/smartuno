<?php

namespace Tests\Feature\Billing;

use App\Http\Middleware\EnforceSubscriptionAccess;
use App\Jobs\GenerateWorkspaceExportJob;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Shared\Models\Contact;
use App\Support\ApiAbilities;
use App\Support\Entitlement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsClientEntitlementStates;
use Tests\TestCase;

/**
 * The subscription gate: after a subscription or trial has been expired for
 * longer than the grace period the client area goes read-only.
 *
 * Two halves, and the second matters more than the first. Blocking a customer
 * who has genuinely stopped paying costs us a little revenue if we get it wrong.
 * Blocking a customer who IS paying — through the other subscription table,
 * through a perpetual admin grant, or because a date could not be read — costs
 * us the customer. So every "must still work" case here is asserted on a real,
 * distinguishable outcome: a row in the database, a job on the queue, a changed
 * password hash.
 */
class SubscriptionAccessTest extends TestCase
{
    use BuildsClientEntitlementStates, RefreshDatabase;

    /**
     * The exact sentence EnforceSubscriptionAccess flashes. Hard-coded rather
     * than re-derived with __(), so the assertion cannot pass by echoing the
     * middleware's own call, and so it keeps meaning the same thing once the
     * Romanian translation lands in lang/ro.json.
     */
    private const BLOCK_MESSAGE = 'Your subscription has ended. You can still see everything, but you cannot send messages or make changes. Reactivate it from Pricing.';

    protected function setUp(): void
    {
        parent::setUp();

        // Entitlement memoises per client id in a static. Tests share one PHP
        // process, so without this a state resolved in an earlier test leaks in.
        Entitlement::forget();

        // Pin the grace window: SAAS_GRACE_DAYS in the developer's .env must not
        // be able to change what these tests mean.
        config(['saas.grace_days' => 7]);

        $this->app->setLocale('en');
    }

    // ─── The gate works ──────────────────────────────────────────────────────

    #[Test]
    public function client_expired_beyond_grace_cannot_create_a_contact(): void
    {
        [$user, $client] = $this->makeClient('Expired Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $response = $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000001'));

        $response->assertRedirect();
        $response->assertSessionHas('error', self::BLOCK_MESSAGE);

        // The response alone proves nothing: assert the write did not happen.
        $this->assertDatabaseMissing('contacts', ['phone_e164' => '+40711000001']);
    }

    #[Test]
    public function client_expired_beyond_grace_cannot_delete_anything(): void
    {
        [$user, $client, $workspace] = $this->makeClient('Expired Deletes Ltd');
        $this->adminGrant($client, now()->subDays(30));

        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Nedelete',
            'phone_e164' => '+40711000002',
        ]);

        $response = $this->actingAs($user)->delete("/app/contacts/{$contact->uuid}");

        $response->assertRedirect();
        $response->assertSessionHas('error', self::BLOCK_MESSAGE);

        // "Nothing is deleted, ever" is the promise the whole feature rests on.
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    #[Test]
    public function client_expired_beyond_grace_can_still_read_everything(): void
    {
        [$user, $client, $workspace] = $this->makeClient('Reader Ltd');
        $this->adminGrant($client, now()->subDays(8));

        Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Vizibil',
            'last_name' => 'DupaExpirare',
            'phone_e164' => '+40711000003',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders($this->inertiaHeaders())
            ->get('/app/contacts');

        $response->assertStatus(200);

        // Not just a 200 — the customer's own data is genuinely still there.
        $this->assertStringContainsString('DupaExpirare', $response->getContent());
    }

    #[Test]
    public function json_write_from_a_readonly_client_returns_402_with_the_machine_readable_code(): void
    {
        [$user, $client] = $this->makeClient('Json Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $response = $this->actingAs($user)
            ->postJson('/app/contacts', $this->contactPayload('+40711000004'));

        // 402, never 403: an integration must be able to tell "not allowed"
        // from "not paid", and the code is the stable contract.
        $response->assertStatus(402);
        $response->assertJson(['code' => 'subscription_readonly']);

        $this->assertDatabaseMissing('contacts', ['phone_e164' => '+40711000004']);
    }

    /**
     * The gate has to cover the token-authenticated API as well as the browser.
     * Without it a read-only client's existing integration keeps sending — on
     * our metered WhatsApp channel — while the web UI says the account is
     * blocked: the same "we only closed one of the two doors" shape that left
     * EnforceLimit inert, one layer out.
     */
    #[Test]
    public function rest_api_write_from_a_readonly_client_is_blocked_with_402(): void
    {
        [$user, $client] = $this->makeClient('Api Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $token = $user->createToken('t', [ApiAbilities::CONTACTS_WRITE])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/contacts', [
            'phone_e164' => '+40711000005',
            'first_name' => 'ApiScris',
            'opt_in_whatsapp' => true,
        ]);

        $response->assertStatus(402);
        $response->assertJson(['code' => 'subscription_readonly']);

        $this->assertDatabaseMissing('contacts', ['phone_e164' => '+40711000005']);
    }

    // ─── The gate does not over-reach ────────────────────────────────────────

    #[Test]
    public function active_client_writes_normally(): void
    {
        [$user, $client] = $this->makeClient('Active Ltd');
        $this->adminGrant($client, now()->addMonth());

        $this->assertSame(Entitlement::ACTIVE, Entitlement::state($client->fresh()));

        $response = $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000010'));

        $response->assertSessionMissing('error');
        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000010']);
    }

    #[Test]
    public function client_three_days_past_expiry_is_in_grace_and_writes_normally(): void
    {
        [$user, $client] = $this->makeClient('Grace Ltd');
        $this->adminGrant($client, now()->subDays(3), ClientSubscription::STATUS_EXPIRED);

        $fresh = $client->fresh();
        $this->assertSame(Entitlement::GRACE, Entitlement::state($fresh));
        // 7 days of grace, 3 already spent.
        $this->assertSame(4, Entitlement::daysLeft($fresh));

        $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000011'))
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000011']);
    }

    #[Test]
    public function client_expired_in_one_table_but_paying_in_the_other_writes_normally(): void
    {
        // THE TWO-TABLE TRAP. This is what left EnforceLimit inert: the admin
        // grant lapsed a month ago, but the firm has been paying Stripe ever
        // since. If this test fails, a paying customer is locked out.
        [$user, $client] = $this->makeClient('Two Table Ltd');
        $this->adminGrant($client, now()->subDays(30), ClientSubscription::STATUS_EXPIRED);
        $this->gatewaySubscription($user, [
            'status' => 'active',
            'ends_at' => now()->addDays(20),
        ]);

        $this->assertSame(Entitlement::ACTIVE, Entitlement::state($client->fresh()));

        $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000012'))
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000012']);
    }

    #[Test]
    public function client_with_a_dead_stripe_row_but_a_live_admin_grant_writes_normally(): void
    {
        // The same trap from the other side: the gateway subscription is long
        // cancelled, but an admin has granted the plan by hand until next year.
        [$user, $client] = $this->makeClient('Reverse Trap Ltd');
        $this->gatewaySubscription($user, [
            'status' => 'cancelled',
            'ends_at' => now()->subDays(120),
        ]);
        $this->adminGrant($client, now()->addYear());

        $this->assertSame(Entitlement::ACTIVE, Entitlement::state($client->fresh()));

        $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000013'))
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000013']);
    }

    #[Test]
    public function a_perpetual_admin_grant_never_expires(): void
    {
        // ends_at NULL is a deliberate decision by an administrator. No amount
        // of elapsed time may take it away.
        [$user, $client] = $this->makeClient('Perpetual Ltd');
        $grant = $this->adminGrant($client, null);
        $grant->forceFill(['starts_at' => now()->subYears(5)])->save();

        $this->assertSame(Entitlement::ACTIVE, Entitlement::state($client->fresh()));

        $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000014'))
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000014']);
    }

    #[Test]
    public function a_dateless_cancelled_grant_does_not_grant_forever(): void
    {
        // Only an *active* dateless row is perpetual. A cancelled one with no
        // date must not become an accidental lifetime licence — but with no end
        // date anywhere there is also nothing to expire, so the fail-open rule
        // applies and the client keeps working. Asserting it explicitly so a
        // future change to compute() cannot flip it to a silent lockout.
        [$user, $client] = $this->makeClient('Dateless Cancelled Ltd');
        $this->adminGrant($client, null, ClientSubscription::STATUS_CANCELLED);

        $this->assertSame(Entitlement::ACTIVE, Entitlement::state($client->fresh()));

        $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000015'))
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000015']);
    }

    #[Test]
    public function a_trial_that_ended_beyond_grace_is_read_only(): void
    {
        // Trials and paid subscriptions are treated identically.
        [$user, $client] = $this->makeClient('Trial Over Ltd');
        $this->gatewaySubscription($user, [
            'status' => 'trialing',
            'ends_at' => null,
            'trial_ends_at' => now()->subDays(8),
        ]);

        $this->assertSame(Entitlement::READONLY, Entitlement::state($client->fresh()));

        $response = $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000016'));

        $response->assertSessionHas('error', self::BLOCK_MESSAGE);
        $this->assertDatabaseMissing('contacts', ['phone_e164' => '+40711000016']);
    }

    #[Test]
    public function a_trial_that_ended_three_days_ago_is_in_grace_and_writes_normally(): void
    {
        [$user, $client] = $this->makeClient('Trial Grace Ltd');
        $this->gatewaySubscription($user, [
            'status' => 'trialing',
            'ends_at' => null,
            'trial_ends_at' => now()->subDays(3),
        ]);

        $fresh = $client->fresh();
        $this->assertSame(Entitlement::GRACE, Entitlement::state($fresh));
        $this->assertSame(4, Entitlement::daysLeft($fresh));

        $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000017'))
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000017']);
    }

    #[Test]
    public function a_client_with_no_subscription_rows_at_all_writes_normally(): void
    {
        // Fail open. An admin-created client that was never given a plan is not
        // a debtor, and locking it out would be our bug, not their invoice.
        [$user, $client] = $this->makeClient('No Rows Ltd');

        $this->assertSame(0, ClientSubscription::where('client_id', $client->id)->count());
        $this->assertSame(Entitlement::ACTIVE, Entitlement::state($client->fresh()));

        $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000018'))
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000018']);
    }

    /**
     * Entitlement promises to fail open on a date it cannot read, and a zero
     * timestamp is the case that nearly slipped through: it is not unreadable —
     * Carbon parses it to the year -0001, which reads as "expired two thousand
     * years ago" and would lock the customer out silently and permanently, with
     * nothing in the log because nothing threw.
     */
    #[Test]
    public function a_client_whose_end_date_is_corrupt_writes_normally(): void
    {
        // A legacy import can leave a zero date in a timestamp column. Whatever
        // that means, it is not evidence that this firm stopped paying, so the
        // resolver must fail open rather than read it as "expired in year zero".
        [$user, $client] = $this->makeClient('Corrupt Date Ltd');
        $grant = $this->adminGrant($client, now()->addMonth());

        // Written past Eloquent and past MySQL's strict mode on purpose: this is
        // the shape of row a migration from an old install actually leaves.
        DB::statement("SET SESSION sql_mode = ''");
        DB::table('client_subscriptions')->where('id', $grant->id)
            ->update(['ends_at' => '0000-00-00 00:00:00']);

        Entitlement::forget();

        $this->assertSame(
            Entitlement::ACTIVE,
            Entitlement::state($client->fresh()),
            'A zero date is corrupt data, not evidence of an unpaid invoice — the resolver must fail open.'
        );

        $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000019'))
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000019']);
    }

    #[Test]
    public function a_converted_trial_still_being_billed_writes_normally(): void
    {
        // THE STRIPE TRAP. StripeGateway::handleSubscriptionUpdated() and sync()
        // both write back the existing trial_ends_at whenever Stripe still
        // reports a trial_end, and Stripe keeps trial_end on the object forever
        // after conversion. So a firm that started a 14-day trial and has been
        // billed successfully ever since carries a trial date weeks in the past
        // on a live row. Reading dates before status locked it out.
        [$user, $client] = $this->makeClient('Converted Trial Ltd');
        $this->gatewaySubscription($user, [
            'status' => 'active',
            'ends_at' => null,
            'trial_ends_at' => now()->subDays(20),
            'renews_at' => now()->addDays(10),
        ]);

        $this->assertSame(Entitlement::ACTIVE, Entitlement::state($client->fresh()));

        $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000021'))
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('contacts', ['phone_e164' => '+40711000021']);
    }

    #[Test]
    public function a_stripe_dunning_cancellation_is_read_only_after_the_grace_period(): void
    {
        // Built the way StripeGateway actually writes it, not with a hand-set
        // ends_at: cancel_at is only populated for a *scheduled* cancellation, so
        // a subscription Stripe cancels after failed payments leaves ends_at NULL
        // and renews_at holding the last period actually paid for. Reading only
        // ends_at/trial_ends_at let the customer who most obviously stopped
        // paying keep full access forever.
        [$user, $client] = $this->makeClient('Dunning Ltd');
        $this->gatewaySubscription($user, [
            'status' => 'canceled',
            'ends_at' => null,
            'trial_ends_at' => null,
            'renews_at' => now()->subDays(40),
        ]);

        $this->assertSame(Entitlement::READONLY, Entitlement::state($client->fresh()));

        $response = $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000022'));

        $response->assertSessionHas('error', self::BLOCK_MESSAGE);
        $this->assertDatabaseMissing('contacts', ['phone_e164' => '+40711000022']);
    }

    #[Test]
    public function an_inertia_write_is_refused_with_the_402_the_front_end_can_show(): void
    {
        // Inertia reads a 302 as success: the modal closes, useForm clears the
        // form, and only 19 of the 146 client pages render flash.error at all, so
        // a redirect told the customer nothing and their contact silently did not
        // exist. The 402 trips Inertia's `invalid` event, which app.jsx turns
        // into a toast on every page — the demo-mode path, reused.
        [$user, $client] = $this->makeClient('Inertia Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $response = $this->actingAs($user)
            ->withHeaders($this->inertiaHeaders())
            ->post('/app/contacts', $this->contactPayload('+40711000023'));

        $response->assertStatus(402);
        $response->assertJson(['code' => 'subscription_readonly']);

        $this->assertDatabaseMissing('contacts', ['phone_e164' => '+40711000023']);
    }

    #[Test]
    public function the_grace_window_follows_the_configured_number_of_days(): void
    {
        [$user, $client] = $this->makeClient('Config Grace Ltd');
        $this->adminGrant($client, now()->subDays(3), ClientSubscription::STATUS_EXPIRED);

        // Same client, same expiry date: only the setting changes.
        config(['saas.grace_days' => 14]);
        Entitlement::forget();
        $this->assertSame(Entitlement::GRACE, Entitlement::state($client->fresh()));

        config(['saas.grace_days' => 2]);
        Entitlement::forget();
        $this->assertSame(Entitlement::READONLY, Entitlement::state($client->fresh()));

        $response = $this->actingAs($user)->post('/app/contacts', $this->contactPayload('+40711000020'));

        $response->assertSessionHas('error', self::BLOCK_MESSAGE);
        $this->assertDatabaseMissing('contacts', ['phone_e164' => '+40711000020']);
    }

    #[Test]
    public function a_user_without_a_client_is_never_blocked(): void
    {
        // Platform staff and any account not attached to a client have no
        // subscription to expire; the gate must not have an opinion about them.
        $orphan = User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => null]);

        $response = $this->passThrough($this->middlewareRequest('client.contacts.store', $orphan));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('passed', $response->getContent());
    }

    #[Test]
    public function reads_are_never_blocked_whatever_the_state(): void
    {
        [$user, $client] = $this->makeClient('Get Ltd');
        $this->adminGrant($client, now()->subYears(2));

        $this->assertSame(Entitlement::READONLY, Entitlement::state($client->fresh()));

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $request = Request::create('/app/contacts', $method);
            $request->setUserResolver(fn () => $user);

            $response = $this->passThrough($request);

            $this->assertSame(200, $response->getStatusCode(), "{$method} must never be blocked");
        }
    }

    // ─── The banner contract (shared Inertia prop) ───────────────────────────

    #[Test]
    public function the_inertia_prop_reports_grace_with_a_day_count_and_an_end_date(): void
    {
        [$user, $client] = $this->makeClient('Prop Grace Ltd');
        $this->adminGrant($client, now()->subDays(3), ClientSubscription::STATUS_EXPIRED);

        $response = $this->actingAs($user)
            ->withHeaders($this->inertiaHeaders())
            ->get('/app/contacts');

        $response->assertStatus(200);
        $response->assertJsonPath('props.subscription.state', 'grace');
        $response->assertJsonPath('props.subscription.days_left', 4);

        $graceEndsAt = CarbonImmutable::parse($response->json('props.subscription.grace_ends_at'));
        $this->assertTrue($graceEndsAt->isFuture());
        $this->assertTrue($graceEndsAt->lessThan(now()->addDays(5)));
    }

    #[Test]
    public function the_inertia_prop_reports_readonly_without_a_countdown(): void
    {
        [$user, $client] = $this->makeClient('Prop Readonly Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $response = $this->actingAs($user)
            ->withHeaders($this->inertiaHeaders())
            ->get('/app/contacts');

        $response->assertStatus(200);
        $response->assertJsonPath('props.subscription.state', 'readonly');
        $response->assertJsonPath('props.subscription.days_left', null);
        $response->assertJsonPath('props.subscription.grace_ends_at', null);
    }

    // ─── They can pay their way out ──────────────────────────────────────────

    #[Test]
    public function a_readonly_client_can_start_a_checkout(): void
    {
        [$user, $client] = $this->makeClient('Checkout Ltd');
        $this->adminGrant($client, now()->subDays(8));

        // An empty payload so no gateway is ever called: reaching the
        // controller's validator is the proof that the gate let the request in.
        $response = $this->actingAs($user)->post('/app/checkout', []);

        $response->assertSessionHasErrors(['plan_id']);
        $response->assertSessionMissing('error');
    }

    #[Test]
    public function a_readonly_client_can_check_a_coupon(): void
    {
        [$user, $client] = $this->makeClient('Coupon Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $response = $this->actingAs($user)
            ->postJson('/app/coupon/check', ['code' => 'NONEXISTENT-CODE']);

        // A 200 answering the question, not the 402 the gate would have sent.
        $response->assertStatus(200);
        $response->assertJson(['valid' => false]);
    }

    #[Test]
    public function a_readonly_client_can_change_plan(): void
    {
        [$user, $client] = $this->makeClient('Change Plan Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $response = $this->actingAs($user)->post('/app/subscription/change-plan', []);

        $response->assertSessionHasErrors(['plan_id']);
        $response->assertSessionMissing('error');
    }

    #[Test]
    public function a_readonly_client_can_cancel_their_subscription(): void
    {
        [$user, $client] = $this->makeClient('Cancel Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $response = $this->actingAs($user)->delete('/app/subscription');

        // The controller answered (this client's plan is admin-managed, so it
        // says so); the gate did not.
        $response->assertRedirect(route('client.subscription.show'));
        $this->assertNotSame(self::BLOCK_MESSAGE, session('error'));
    }

    #[Test]
    public function a_readonly_client_can_open_a_support_ticket(): void
    {
        [$user, $client] = $this->makeClient('Support Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $this->actingAs($user)->post('/app/support', [
            'subject' => 'De ce nu mai pot trimite mesaje',
            'message' => 'Am platit ieri prin transfer bancar.',
        ]);

        // A real row: the cheapest resolution path for someone who believes the
        // block is a mistake must actually reach us.
        $this->assertDatabaseHas('support_tickets', [
            'user_id' => $user->id,
            'subject' => 'De ce nu mai pot trimite mesaje',
        ]);
    }

    #[Test]
    public function a_readonly_client_can_still_request_their_gdpr_export(): void
    {
        Queue::fake();

        [$user, $client] = $this->makeClient('Export Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $this->actingAs($user)->post('/app/settings/data-export');

        // A legal right does not depend on an unpaid invoice.
        Queue::assertPushed(GenerateWorkspaceExportJob::class);
    }

    #[Test]
    public function a_readonly_client_can_log_out(): void
    {
        [$user, $client] = $this->makeClient('Logout Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
    }

    #[Test]
    public function a_readonly_client_can_change_their_password(): void
    {
        [$user, $client] = $this->makeClient('Password Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'parola-noua-sigura-1',
            'password_confirmation' => 'parola-noua-sigura-1',
        ]);

        // Securing the account is never a "change" we block.
        $this->assertTrue(Hash::check('parola-noua-sigura-1', $user->fresh()->password));
    }

    // ─── The allowlist itself ────────────────────────────────────────────────

    #[Test]
    public function every_allowlisted_route_name_resolves_to_a_real_route(): void
    {
        // A typo in this list silently allowlists nothing and traps the
        // customer, so the list is read out of the middleware itself rather
        // than retyped here.
        $names = $this->allowlist();

        $this->assertGreaterThan(10, count($names), 'The allowlist was read but came back suspiciously short.');

        foreach ($names as $name) {
            $this->assertTrue(
                Route::has($name),
                "Allowlisted route name [{$name}] does not exist — it allowlists nothing."
            );
        }
    }

    #[Test]
    public function every_allowlisted_route_name_passes_the_gate_for_a_readonly_client(): void
    {
        [$user, $client] = $this->makeClient('Allowlist Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $this->assertSame(Entitlement::READONLY, Entitlement::state($client->fresh()));

        foreach ($this->allowlist() as $name) {
            $response = $this->passThrough($this->middlewareRequest($name, $user));

            $this->assertSame(
                'passed',
                $response->getContent(),
                "Allowlisted route [{$name}] was blocked for a read-only client."
            );
        }

        // The control: the same request shape, a name that is not on the list.
        // Without this the loop above would still pass if the gate were inert.
        $blocked = $this->passThrough($this->middlewareRequest('client.contacts.store', $user));

        $this->assertSame(402, $blocked->getStatusCode());
        $this->assertSame('subscription_readonly', $blocked->getData(true)['code']);
    }

    #[Test]
    public function the_unnamed_password_confirmation_post_is_matched_by_path(): void
    {
        // Laravel registers POST /confirm-password without a name, so the
        // allowlist cannot cover it; the middleware matches the path instead.
        [$user, $client] = $this->makeClient('Confirm Password Ltd');
        $this->adminGrant($client, now()->subDays(8));

        $request = Request::create('/confirm-password', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        $response = $this->passThrough($request);

        $this->assertSame('passed', $response->getContent());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    // makeClient(), adminGrant(), gatewaySubscription() and plan() now live in
    // Tests\Concerns\BuildsClientEntitlementStates, so the background gate in
    // BackgroundSubscriptionAccessTest is proved against the very same fixtures.

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(string $phone): array
    {
        return [
            'phone_e164' => $phone,
            'first_name' => 'Ion',
            'last_name' => 'Popescu',
            'opt_in_whatsapp' => true,
        ];
    }

    /**
     * Inertia's asset version must match or Inertia answers 409 instead of
     * rendering the page — the same idiom MultiTenantScopingTest uses.
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

    /**
     * The allowlist as the middleware itself holds it.
     *
     * @return list<string>
     */
    private function allowlist(): array
    {
        $constant = (new ReflectionClass(EnforceSubscriptionAccess::class))
            ->getReflectionConstant('ALLOWED_ROUTE_NAMES');

        $this->assertNotFalse($constant, 'EnforceSubscriptionAccess::ALLOWED_ROUTE_NAMES no longer exists.');

        return array_values($constant->getValue());
    }

    /**
     * A JSON write request that resolves to the given route name, so the
     * allowlist check (which reads $request->route()->getName()) fires. JSON so
     * a block comes back as a 402 and no session/redirect machinery is involved.
     */
    private function middlewareRequest(string $name, User $user): Request
    {
        $request = Request::create('/x', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->setRouteResolver(fn () => (new RoutingRoute(['POST'], '/x', []))->name($name));
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function passThrough(Request $request): Response
    {
        Entitlement::forget();

        return (new EnforceSubscriptionAccess)->handle($request, fn () => response('passed', 200));
    }
}
