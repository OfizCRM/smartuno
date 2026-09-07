<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBusinessHour;
use App\Models\ClientProfile;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Rules\ValidCui;
use App\Rules\ValidIban;
use App\Services\AuditLogService;
use App\Support\Romania;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    public function __construct(private AuditLogService $auditLog) {}

    public function index(Request $request): Response
    {
        $this->authorizeForUser($request->user('admin'), 'viewAny', Client::class);

        $query = Client::query()->with('activeSubscription.plan');

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $clients = $query->orderBy('name')->paginate(20)->withQueryString()->through(function (Client $c) {
            // Effective plan: admin-assigned ClientSubscription, else the plan from a
            // user's own active Subscription — matches what the client's dashboard shows.
            $plan = $c->effectivePlan();

            return [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'address' => $c->address,
                'status' => $c->status,
                'base_currency' => $c->base_currency,
                'currency_symbol' => $c->currency_symbol,
                'currency_position' => $c->currency_position,
                'subscription' => $plan ? ['name' => $plan->name] : null,
            ];
        });

        $plans = Plan::where('enabled', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug', 'currency_code', 'monthly_price_cents', 'yearly_price_cents']);

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
            'plans' => $plans->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'currency_code' => $p->currency_code,
                'monthly_price_cents' => $p->monthly_price_cents,
                'yearly_price_cents' => $p->yearly_price_cents,
            ]),
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Detail page for one client: the company profile the platform admin may edit,
     * plus a read-only view of the operating data the tenant maintains itself.
     */
    public function show(Request $request, Client $client): Response
    {
        $this->authorizeForUser($request->user('admin'), 'view', $client);

        $client->load(['profile', 'businessHours', 'activeSubscription.plan']);

        // Same reason the list uses it: a client whose plan comes from a user-level
        // Subscription would otherwise read "No Plan" here while their own dashboard
        // shows the plan.
        $plan = $client->effectivePlan();

        return Inertia::render('Admin/Clients/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'phone' => $client->phone,
                // Derived from the profile address on every profile save; shown here so
                // an admin can see what a client migrated from the free-text era has.
                'address' => $client->address,
                'status' => $client->status,
                'base_currency' => $client->base_currency,
                'currency_symbol' => $client->currency_symbol,
                'currency_position' => $client->currency_position,
                'logo_url' => $client->logoUrl(),
                'created_at' => $client->created_at?->toDateString(),
            ],
            'profile' => $client->profile?->only([
                'legal_name', 'industry', 'industry_other', 'company_size', 'short_description',
                'cui', 'vat_status', 'vat_rate', 'trade_register_no', 'share_capital', 'iban', 'bank_name',
                'mobile_phone', 'website', 'contact_person_name', 'contact_person_role',
                'address_street', 'address_city', 'address_county', 'address_postcode', 'address_country',
                'timezone', 'delivery_zones', 'delivery_time',
                'facebook_url', 'instagram_url', 'google_maps_url', 'online_shop_url',
            ]),
            // One row per interval, so a clinic closing for lunch sends two Monday rows.
            'businessHours' => $client->businessHours->map(fn (ClientBusinessHour $hour) => [
                'id' => $hour->id,
                'day_of_week' => (int) $hour->day_of_week,
                'opens_at' => $hour->opens_at ? substr((string) $hour->opens_at, 0, 5) : null,
                'closes_at' => $hour->closes_at ? substr((string) $hour->closes_at, 0, 5) : null,
                'is_closed' => $hour->is_closed,
            ])->values(),
            'plan' => $plan ? ['name' => $plan->name] : null,
            'userCount' => $client->users()->count(),
            // Only the lists this form actually offers. Industries and company sizes are
            // the tenant's own fields, read-only here, so their option lists stay client-side.
            'options' => [
                'counties' => Romania::counties(),
                'vat_statuses' => Romania::vatStatuses(),
                // Shown as a hint next to the rate field. The rate itself stays free
                // text: 11% goods are ordinary here, not an exception to correct.
                'standard_vat_rate' => Romania::standardVatRate(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'create', Client::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'base_currency' => ['nullable', 'string', 'max:10'],
            'currency_symbol' => ['nullable', 'string', 'max:16'],
            'currency_position' => ['nullable', 'string', 'in:before,after'],
        ]);

        $validated['status'] = $validated['status'] ?? 'active';
        // Currency fields left null inherit the platform default currency.

        $client = Client::create($validated);

        $this->auditLog->logAdmin('client.created', Client::class, (int) $client->id, ['name' => $client->name]);

        return redirect()->route('admin.clients.index')->with('success', __('Client created.'));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'update', $client);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'base_currency' => ['nullable', 'string', 'max:10'],
            'currency_symbol' => ['nullable', 'string', 'max:16'],
            'currency_position' => ['nullable', 'string', 'in:before,after'],
        ]);

        $client->update($validated);

        $this->auditLog->logAdmin('client.updated', Client::class, (int) $client->id, ['name' => $client->name]);

        return redirect()->back()->with('success', __('Client updated.'));
    }

    /**
     * The company profile as the *platform* needs it: what goes on an invoice, who to
     * call, where to send post. Deliberately a separate endpoint from update() so the
     * list page's edit modal keeps owning the platform record (name, status, currency).
     *
     * Industry, company size, description, delivery and social links are the tenant's
     * own operating data and are editable only inside the client app — they are shown
     * here read-only.
     */
    public function updateProfile(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'update', $client);

        $rules = [
            'legal_name' => ['nullable', 'string', 'max:255'],
            'cui' => ['nullable', 'string', 'max:16', new ValidCui],
            'trade_register_no' => ['nullable', 'string', 'max:32'],
            'vat_status' => ['nullable', 'string', Rule::in(Romania::vatStatuses())],
            // decimal(5,2) in the column, but a rate over 100% is a typo, not a rate.
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'iban' => ['nullable', 'string', 'max:34', new ValidIban],
            'bank_name' => ['nullable', 'string', 'max:128'],
            'share_capital' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'contact_person_name' => ['nullable', 'string', 'max:128'],
            'contact_person_role' => ['nullable', 'string', 'max:128'],
            'mobile_phone' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'url', 'max:255'],
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:128'],
            'address_county' => ['nullable', 'string', Rule::in(array_keys(Romania::counties()))],
            'address_postcode' => ['nullable', 'string', 'max:16'],
            'address_country' => ['nullable', 'string', 'size:2'],
        ];

        $validated = $request->validate($rules);

        // Missing keys become explicit nulls: array_filter here would make a field the
        // admin cleared impossible to clear, because the blank would just be skipped.
        $data = array_merge(array_fill_keys(array_keys($rules), null), $validated);

        // Stored uppercase and unspaced so the value that reaches an invoice or a
        // payment file is the canonical one, whatever the admin pasted. cui goes
        // through the shared normaliser rather than being stored verbatim: it is an
        // indexed lookup column, and "RO 14399840" typed here has to match
        // "14399840" typed by the tenant in its own company form.
        $data['iban'] = $data['iban'] ? strtoupper(str_replace(' ', '', $data['iban'])) : null;
        $data['cui'] = Romania::canonicalCui($data['cui']);
        $data['address_country'] = $data['address_country'] ? strtoupper($data['address_country']) : null;

        // A rate only means something alongside a status that charges it — a
        // neplatitor de TVA carries the exemption mention instead. Keyed on the two
        // statuses that do charge, not on 'none' alone, so clearing the status back
        // to unset does not leave a stale rate behind. Same coupling the client-side
        // company form applies.
        if (! in_array($data['vat_status'], ['standard', 'on_collection'], true)) {
            $data['vat_rate'] = null;
        }

        ClientProfile::updateOrCreate(['client_id' => $client->id], $data);

        $derived = Romania::composeAddress($data);
        if ($derived !== null) {
            // clients.address is derived, not authoritative: the admin list and the CSV
            // export still read that one column. Left untouched when the structured
            // address is empty, so a first save does not wipe a legacy free-text address
            // that nobody has re-entered yet.
            $client->update(['address' => $derived]);
        }

        $this->auditLog->logAdmin('client.profile.updated', Client::class, (int) $client->id, ['name' => $client->name]);

        return redirect()->back()->with('success', __('Client profile updated.'));
    }

    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'delete', $client);

        $name = $client->name;
        $client->delete();

        $this->auditLog->logAdmin('client.deleted', null, null, ['name' => $name]);

        return redirect()->route('admin.clients.index')->with('success', __('Client deleted.'));
    }

    public function users(Request $request, Client $client): JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'view', $client);

        $users = $client->users()->orderBy('created_at')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'client_role' => $u->client_role ?? 'staff',
            'status' => $u->status ?? 'active',
            'created_at' => $u->created_at->toIso8601String(),
        ]);

        return response()->json(['users' => $users, 'client' => ['id' => $client->id, 'name' => $client->name]]);
    }

    public function storeUser(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'update', $client);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'client_role' => ['required', 'string', 'in:administrator,staff'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $user = $client->users()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'client_role' => $validated['client_role'],
            'status' => $validated['status'],
        ]);

        $this->auditLog->logAdmin('client.user_created', User::class, (int) $user->id, ['client_id' => $client->id, 'email' => $user->email]);

        if ($request->wantsJson()) {
            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'client_role' => $user->client_role,
                    'status' => $user->status,
                    'created_at' => $user->created_at->toIso8601String(),
                ],
            ], 201);
        }

        return redirect()->back()->with('success', __('User added.'));
    }

    public function updateUser(Request $request, Client $client, User $user): RedirectResponse|JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'update', $client);
        if ($user->client_id !== $client->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'client_role' => ['required', 'string', 'in:administrator,staff'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->client_role = $validated['client_role'];
        $user->status = $validated['status'];
        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }
        $user->save();

        $this->auditLog->logAdmin('client.user_updated', User::class, (int) $user->id, ['client_id' => $client->id]);

        if ($request->wantsJson()) {
            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'client_role' => $user->client_role,
                    'status' => $user->status,
                    'created_at' => $user->created_at->toIso8601String(),
                ],
            ]);
        }

        return redirect()->back()->with('success', __('User updated.'));
    }

    public function destroyUser(Request $request, Client $client, User $user): RedirectResponse|JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'update', $client);
        if ($user->client_id !== $client->id) {
            abort(404);
        }

        $adminCount = $client->users()->where('client_role', User::CLIENT_ROLE_ADMINISTRATOR)->count();
        if ($user->client_role === User::CLIENT_ROLE_ADMINISTRATOR && $adminCount <= 1) {
            if ($request->wantsJson()) {
                return response()->json(['message' => __('Cannot delete the last client administrator.')], 422);
            }

            return redirect()->back()->with('error', __('Cannot delete the last client administrator.'));
        }

        $user->delete();
        $this->auditLog->logAdmin('client.user_deleted', User::class, null, ['client_id' => $client->id, 'email' => $user->email]);

        if ($request->wantsJson()) {
            return response()->json([], 204);
        }

        return redirect()->back()->with('success', __('User removed.'));
    }

    public function assignPlan(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        $this->authorizeForUser($request->user('admin'), 'assignPlan', $client);

        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        $client->clientSubscriptions()->where('status', ClientSubscription::STATUS_ACTIVE)->update(['status' => ClientSubscription::STATUS_CANCELLED, 'ends_at' => now()]);

        $sub = $client->clientSubscriptions()->create([
            'plan_id' => $plan->id,
            'billing_cycle' => $validated['billing_cycle'],
            'starts_at' => now(),
            'status' => ClientSubscription::STATUS_ACTIVE,
            'assigned_by_admin_id' => $request->user('admin')->id,
        ]);

        $this->auditLog->logAdmin('client.plan_assigned', Client::class, (int) $client->id, [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'billing_cycle' => $validated['billing_cycle'],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'subscription' => [
                    'id' => $sub->id,
                    'plan' => ['name' => $plan->name],
                    'billing_cycle' => $sub->billing_cycle,
                ],
            ]);
        }

        return redirect()->back()->with('success', __('Plan assigned.'));
    }

    public function impersonate(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'impersonate', $client);

        if ($request->session()->get('impersonating')) {
            return redirect()->route('admin.clients.index')->with('error', __('Already impersonating.'));
        }

        $targetUser = $client->users()
            ->where('status', User::STATUS_ACTIVE)
            ->orderByRaw("CASE WHEN client_role = 'administrator' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->first();

        if (! $targetUser) {
            return redirect()->back()->with('error', __('Client has no active users. Add a user first.'));
        }

        $admin = $request->user('admin');

        $request->session()->put('impersonator_admin_id', $admin->id);
        $request->session()->put('impersonating', true);
        $request->session()->put('impersonated_client_id', $client->id);

        Auth::guard('web')->login($targetUser, $request->boolean('remember', false));

        $this->auditLog->logAdmin('impersonation.started', User::class, (int) $targetUser->id, [
            'client_id' => $client->id,
            'client_name' => $client->name,
        ]);

        return redirect()->route('client.dashboard');
    }

    /**
     * Export all clients as a CSV download.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorizeForUser($request->user('admin'), 'viewAny', Client::class);

        $q = $request->get('search');

        return response()->streamDownload(function () use ($q) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Email', 'Phone', 'Status', 'Plan', 'Created At']);

            Client::with('activeSubscription.plan')
                ->when($q, fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))
                ->orderBy('name')
                ->chunk(200, function ($clients) use ($handle) {
                    foreach ($clients as $c) {
                        fputcsv($handle, [
                            $c->id,
                            $c->name,
                            $c->email,
                            $c->phone,
                            $c->status,
                            $c->activeSubscription?->plan?->name ?? '',
                            $c->created_at->toDateString(),
                        ]);
                    }
                });

            fclose($handle);
        }, 'clients_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
