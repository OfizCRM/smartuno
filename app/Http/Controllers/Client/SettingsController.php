<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBusinessHour;
use App\Models\ClientProfile;
use App\Models\ClientSetting;
use App\Models\Currency;
use App\Models\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Social\Models\SocialAccount;
use App\Rules\ValidCui;
use App\Rules\ValidIban;
use App\Services\StorageManager;
use App\Support\Romania;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * The client_profiles data columns, in the order the form shows them.
     *
     * One list drives the read payload, the validation and the write, so a
     * column added later cannot end up readable but not saveable.
     *
     * @var list<string>
     */
    private const PROFILE_FIELDS = [
        'legal_name', 'industry', 'industry_other', 'company_size', 'short_description',
        'cui', 'vat_status', 'vat_rate', 'trade_register_no', 'share_capital', 'iban', 'bank_name',
        'mobile_phone', 'website', 'contact_person_name', 'contact_person_role',
        'address_street', 'address_city', 'address_county', 'address_postcode', 'address_country',
        'timezone', 'delivery_zones', 'delivery_time',
        'facebook_url', 'instagram_url', 'google_maps_url', 'online_shop_url',
    ];

    /**
     * The settings hub: a list of rows, each linking to a page at its own route.
     *
     * Everything a firm configures once lives behind here rather than in the
     * sidebar. The counts below drive the status chips on each row, which is what
     * lets someone answer "have I set this up?" without opening the page — the
     * main thing a hub loses against a flat menu, bought back cheaply.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;
        $isAdmin = $user->client_id && $user->isClientAdministrator();

        return Inertia::render('client/Settings/Hub', [
            'isAdmin' => (bool) $isAdmin,
            'status' => [
                'team' => $isAdmin ? User::where('client_id', $user->client_id)->count() : 0,
                'workspaces' => $user->client_id ? Workspace::where('client_id', $user->client_id)->count() : 0,
                'channels' => ChannelAccount::where('workspace_id', $workspaceId)->count(),
                'social' => SocialAccount::where('workspace_id', $workspaceId)->where('active', true)->count(),
                'stores' => EcommerceStore::where('workspace_id', $workspaceId)->count(),
                'sms' => SmsProviderConfig::where('workspace_id', $workspaceId)->exists(),
                'email' => WorkspaceSmtpConfig::where('workspace_id', $workspaceId)->where('is_active', true)->exists(),
            ],
        ]);
    }

    /** Personal preferences: language, currency, theme, timezone. */
    public function preferences(Request $request): Response
    {
        $user = $request->user();
        $supportedLocales = Locale::enabled()->orderByRaw('is_default DESC')->orderBy('sort_order')->get(['code', 'name']);
        if ($supportedLocales->isEmpty()) {
            $supportedLocales = collect([['code' => 'en', 'name' => 'English']]);
        }
        $supportedCurrencies = Currency::where('enabled', true)->orderBy('code')->get(['code', 'symbol']);

        return Inertia::render('client/Settings/Preferences', [
            'preferences' => [
                'locale' => $user->locale ?? config('app.locale', 'en'),
                'display_currency' => $user->display_currency ?? 'RON',
                'theme' => $user->theme ?? 'light',
                'timezone' => $user->timezone ?? 'Europe/Bucharest',
            ],
            'supportedLocales' => $supportedLocales->map(fn ($l) => ['code' => $l->code, 'name' => $l->name]),
            'supportedCurrencies' => $supportedCurrencies->map(fn ($c) => ['code' => $c->code, 'name' => $c->code, 'symbol' => $c->symbol ?? $c->code]),
        ]);
    }

    /**
     * The organisation's own details. Administrators only, like TeamController.
     *
     * Far more than a name and a phone number now: the fiscal block ends up on
     * invoices, and the description, opening hours, delivery zones and public
     * links are what the AI agent answers customers with. A blank field here is
     * a question the agent cannot answer.
     */
    public function company(Request $request): Response
    {
        $c = $this->administeredClient($request);
        $c->load(['profile', 'businessHours']);

        // A cloud disk needs its DB-held credentials pushed into the runtime
        // config before a URL can be built for it.
        if ($c->logo_path) {
            app(StorageManager::class)->ensureDiskReady($c->logo_disk ?? 'public');
        }

        return Inertia::render('client/Settings/Company', [
            'client' => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'logo_url' => $c->logoUrl(),
            ],
            'profile' => $this->profilePayload($c->profile),
            'hours' => $this->hoursPayload($c->businessHours),
            'options' => [
                'counties' => Romania::counties(),
                'industries' => Romania::industries(),
                'company_sizes' => Romania::companySizes(),
                'vat_statuses' => Romania::vatStatuses(),
                'default_vat_rate' => Romania::standardVatRate(),
            ],
        ]);
    }

    /**
     * Save the whole company form: the client row, its profile and its hours.
     *
     * Deliberately not folded into update(), which serves three pages through
     * one flat rule list — thirty-five more rules on that endpoint would make
     * every other save on it harder to reason about.
     */
    public function updateCompany(Request $request): RedirectResponse
    {
        $c = $this->administeredClient($request);

        $validator = Validator::make($request->all(), [
            // Never nullable: this is what names the tenant everywhere else in
            // the product — the impersonation banner, the admin list, workspaces.
            'client_name' => ['required', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:64'],

            'legal_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', Rule::in(Romania::industries())],
            'industry_other' => ['nullable', 'string', 'max:255'],
            'company_size' => ['nullable', 'string', Rule::in(Romania::companySizes())],
            'short_description' => ['nullable', 'string', 'max:1000'],

            'cui' => ['nullable', 'string', 'max:16', new ValidCui],
            'vat_status' => ['nullable', 'string', Rule::in(Romania::vatStatuses())],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'trade_register_no' => ['nullable', 'string', 'max:32'],
            // The column is decimal(15,2) and MySQL runs in strict mode: without
            // the cap an over-long number is a 500 from inside the transaction
            // rather than a message under the field.
            'share_capital' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'iban' => ['nullable', 'string', 'max:34', new ValidIban],
            'bank_name' => ['nullable', 'string', 'max:128'],

            'mobile_phone' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'url', 'max:255'],
            'contact_person_name' => ['nullable', 'string', 'max:128'],
            'contact_person_role' => ['nullable', 'string', 'max:128'],

            'address_street' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:128'],
            'address_county' => ['nullable', 'string', Rule::in(array_keys(Romania::counties()))],
            'address_postcode' => ['nullable', 'string', 'max:16'],
            'address_country' => ['nullable', 'string', 'size:2'],

            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
            'delivery_zones' => ['nullable', 'string', 'max:2000'],
            'delivery_time' => ['nullable', 'string', 'max:255'],

            'facebook_url' => ['nullable', 'url', 'max:255'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'google_maps_url' => ['nullable', 'url', 'max:512'],
            'online_shop_url' => ['nullable', 'url', 'max:255'],

            // Seven days, with room for the second interval the schema is built
            // for. Uncapped, one request could insert rows without limit and
            // make every later read of this client — the admin detail page
            // included — hydrate them all.
            'hours' => ['array', 'max:14'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'hours.*.is_closed' => ['boolean'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            /** @var array<array-key, mixed> $submitted */
            $submitted = (array) $request->input('hours', []);

            foreach ($submitted as $key => $row) {
                if (! is_array($row) || filter_var($row['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $opens = is_string($row['opens_at'] ?? null) ? $row['opens_at'] : '';
                $closes = is_string($row['closes_at'] ?? null) ? $row['closes_at'] : '';

                // One time without the other is a typo. Both blank is not: it
                // means the hours have not been filled in yet, and
                // replaceBusinessHours() then writes no row at all rather than
                // one claiming the firm is open at hours nobody has given.
                if (($opens === '') !== ($closes === '')) {
                    $validator->errors()->add("hours.{$key}.closes_at", __('Set both an opening and a closing time, or mark the day as closed.'));

                    continue;
                }

                if ($opens === '') {
                    continue;
                }

                // Zero-padded H:i compares correctly as a string. A day that
                // closes before it opens is not an overnight bar in v1, it is a
                // typo — and it would make the agent answer "are you open now"
                // backwards for the rest of the day.
                if (strcmp($closes, $opens) <= 0) {
                    $validator->errors()->add("hours.{$key}.closes_at", __('The closing time must be after the opening time.'));
                }
            }
        });

        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        DB::transaction(function () use ($c, $validated): void {
            $c->name = $validated['client_name'];
            $c->email = $validated['client_email'] ?? null;
            $c->phone = $validated['client_phone'] ?? null;

            $profileData = [];
            foreach (self::PROFILE_FIELDS as $field) {
                // Every key, nulls included. array_filter() here would make a
                // field impossible to clear once it had been filled in once —
                // the bug ClientBrandingController::update still has.
                $profileData[$field] = $validated[$field] ?? null;
            }

            // A rate only means something alongside a status that charges it.
            // Keyed on the two statuses that do, not on 'none' alone: clearing
            // the status back to unset would otherwise leave a stale 21 behind
            // in a field the form then stops showing.
            if (! in_array($profileData['vat_status'] ?? null, ['standard', 'on_collection'], true)) {
                $profileData['vat_rate'] = null;
            }

            // cui is indexed and will be looked up by later work; "RO 14399840"
            // and "RO14399840" must not end up as two different firms. Shared
            // with the admin profile form, which writes the same column.
            $profileData['cui'] = Romania::canonicalCui($profileData['cui']);
            $profileData['iban'] = $this->canonical($profileData['iban'], '/\s+/');

            ClientProfile::updateOrCreate(['client_id' => $c->id], $profileData);

            $this->replaceBusinessHours($c, $validated['hours'] ?? []);

            // clients.address is DERIVED from the structured address above and
            // is no longer a source of truth. It stays written because the admin
            // client list and the CSV export still read that one column.
            //
            // Left untouched when the structured address is empty, exactly as
            // ClientController::updateProfile does: nothing backfills the four
            // structured fields from the old free-text column, so an unguarded
            // write would erase a legacy address on the tenant's first save.
            $derived = Romania::composeAddress($validated);
            if ($derived !== null) {
                $c->address = $derived;
            }
            $c->save();
        });

        return back()->with('success', __('Settings saved.'));
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $c = $this->administeredClient($request);

        // SVG is deliberately absent from the list: one served from the public
        // disk can carry a script, and it would be same-origin with the app on
        // every page that renders the logo. 'image' also rejects it in Laravel 12
        // without allow_svg, but the rule set should say so on its own rather
        // than rest on a framework default one word can flip.
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,gif,webp', 'max:2048'],
        ]);

        $file = $request->file('logo');
        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        $sm = app(StorageManager::class);
        $disk = $sm->diskName();
        // extension() guesses from the file's own mime type. The client-supplied
        // name must not decide it: "logo.php" holding a valid PNG passes the
        // mimes rule, and a webserver that runs PHP under the uploads directory
        // would then execute what we stored.
        $path = $sm->prefixedPath('client-logos/'.Str::uuid().'.'.($file->extension() ?: 'png'));
        $sm->disk()->putFileAs(dirname($path), $file, basename($path));

        // Only now is the old file safe to drop. Deleting it first — which is
        // what avoids the orphan on a paid object store — leaves logo_path
        // naming a file that no longer exists if the write above throws.
        $previousPath = $c->logo_path;
        $previousDisk = $c->logo_disk;

        $c->logo_path = $path;
        $c->logo_disk = $disk;
        $c->save();

        $this->deleteLogoFile($previousPath, $previousDisk);

        return back()->with('success', __('Logo uploaded.'));
    }

    public function deleteLogo(Request $request): RedirectResponse
    {
        $c = $this->administeredClient($request);

        $this->deleteLogoFile($c->logo_path, $c->logo_disk);
        $c->logo_path = null;
        $c->logo_disk = null;
        $c->save();

        return back()->with('success', __('Logo removed.'));
    }

    public function notifications(Request $request): Response
    {
        $user = $request->user();

        $preferences = $user->notificationPreferences
            ->groupBy('event')
            ->map(fn ($group) => $group->mapWithKeys(fn ($p) => [$p->channel => (bool) $p->enabled]));

        return Inertia::render('client/Settings/Notifications', [
            'preferences' => $preferences,
            // The weekly digest lives here rather than on the preferences form:
            // every email switch in one place is what a user expects, and it lets
            // the digest email itself deep-link to the switch that turns it off.
            'digestEnabled' => $user->client_id
                ? ClientSetting::get($user->client_id, 'weekly_digest_enabled', '1') !== '0'
                : true,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $localeCodes = Locale::enabled()->pluck('code')->all() ?: ['en'];
        $currencyCodes = Currency::where('enabled', true)->pluck('code')->all() ?: ['USD'];

        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:16', Rule::in($localeCodes)],
            'display_currency' => ['nullable', 'string', 'max:10', Rule::in($currencyCodes)],
            'theme' => ['nullable', 'string', 'in:light,dark'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:64'],
            'client_address' => ['nullable', 'string'],
            'weekly_digest_enabled' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('locale', $validated) && $validated['locale'] !== null) {
            $user->locale = $validated['locale'];
        }
        if (array_key_exists('display_currency', $validated) && $validated['display_currency'] !== null) {
            $user->display_currency = $validated['display_currency'];
        }
        if (array_key_exists('theme', $validated) && $validated['theme'] !== null) {
            $user->theme = $validated['theme'];
        }
        if (array_key_exists('timezone', $validated) && $validated['timezone'] !== null) {
            $user->timezone = $validated['timezone'];
        }
        $user->save();

        if ($user->client_id && $user->isClientAdministrator() && $user->client) {
            $client = $user->client;
            if (array_key_exists('client_name', $validated)) {
                $client->name = $validated['client_name'] ?? $client->name;
            }
            if (array_key_exists('client_email', $validated)) {
                $client->email = $validated['client_email'];
            }
            if (array_key_exists('client_phone', $validated)) {
                $client->phone = $validated['client_phone'];
            }
            if (array_key_exists('client_address', $validated)) {
                $client->address = $validated['client_address'];
            }
            $client->save();
        }

        if ($user->client_id && array_key_exists('weekly_digest_enabled', $validated)) {
            ClientSetting::set($user->client_id, 'weekly_digest_enabled', $validated['weekly_digest_enabled'] ? '1' : '0');
        }

        // back(), not a fixed route: this action now serves three separate pages
        // (preferences, company, notifications) and a save should return you to
        // the one you were on rather than bouncing you to the hub.
        return back()->with('success', __('Settings saved.'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Company form internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The gate every company screen shares: an administrator, of a client that
     * exists. Re-checked on each action rather than trusted from the read side,
     * and it returns the client so nothing downstream has to reach for it again.
     */
    private function administeredClient(Request $request): Client
    {
        $user = $request->user();
        abort_unless($user->client_id && $user->isClientAdministrator(), 403);

        $c = $user->client;
        abort_unless($c instanceof Client, 404);

        return $c;
    }

    /**
     * The profile as a flat array the form can bind to, whether or not a row
     * exists yet — a half-populated form object would make every field in the
     * page need its own null guard.
     *
     * @return array<string, mixed>
     */
    private function profilePayload(?ClientProfile $profile): array
    {
        $data = $profile
            ? $profile->only(self::PROFILE_FIELDS)
            : array_fill_keys(self::PROFILE_FIELDS, null);

        $data['address_country'] ??= 'RO';
        $data['timezone'] ??= 'Europe/Bucharest';

        return $data;
    }

    /**
     * Exactly seven entries, keyed 1 (Monday) to 7 (Sunday).
     *
     * The table holds one row per interval, so a clinic closed for lunch has two
     * Monday rows; v1 shows the first one of each day and leaves the rest alone.
     * Days with no row at all start as a normal Romanian week — open Monday to
     * Friday with the times still to fill in, closed at the weekend.
     *
     * @param  Collection<int, ClientBusinessHour>  $hours
     * @return array<int, array<string, mixed>>
     */
    private function hoursPayload(Collection $hours): array
    {
        $byDay = $hours->groupBy('day_of_week');

        $payload = [];
        for ($day = 1; $day <= 7; $day++) {
            $row = $byDay->get($day)?->first();

            $payload[$day] = [
                'day_of_week' => $day,
                'opens_at' => $row ? $this->asHourMinute($row->opens_at) : null,
                'closes_at' => $row ? $this->asHourMinute($row->closes_at) : null,
                'is_closed' => $row ? (bool) $row->is_closed : $day >= 6,
            ];
        }

        return $payload;
    }

    /** MySQL hands back "09:00:00"; an <input type="time"> wants "09:00". */
    private function asHourMinute(mixed $time): ?string
    {
        return is_string($time) && $time !== '' ? substr($time, 0, 5) : null;
    }

    /**
     * Rewrite the intervals this form owns, which is the first one of each day.
     *
     * Not a full wipe: the table has no unique(client_id, day_of_week) precisely
     * so a clinic can hold Monday 09:00-13:00 and 15:00-19:00, and deleting
     * everything would destroy the second interval every time someone saved the
     * v1 form — the guarantee hoursPayload()'s docblock makes. A day toggled
     * closed is the one case where the later intervals do go, because a closed
     * day that still holds an open interval says both things at once.
     *
     * A day left open with no times is written as no row at all. "Closed on
     * Sunday" and "nobody has filled this in yet" have to stay distinguishable —
     * only the first is safe to tell a customer — and an is_closed = false row
     * with null times is neither while positively asserting the firm is open.
     *
     * @param  array<array-key, mixed>  $submitted
     */
    private function replaceBusinessHours(Client $c, array $submitted): void
    {
        $now = now();
        $rows = [];
        $closedDays = [];

        foreach ($submitted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $day = (int) $row['day_of_week'];
            $isClosed = filter_var($row['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $opens = $isClosed ? null : (($row['opens_at'] ?? null) ?: null);
            $closes = $isClosed ? null : (($row['closes_at'] ?? null) ?: null);

            if ($isClosed) {
                $closedDays[] = $day;
            } elseif ($opens === null || $closes === null) {
                continue;
            }

            $rows[] = [
                'client_id' => $c->id,
                'day_of_week' => $day,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'is_closed' => $isClosed,
                // v1 writes a single interval per day; a second one would be
                // sort_order 1 and needs no migration.
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ClientBusinessHour::where('client_id', $c->id)
            ->where(function ($q) use ($closedDays) {
                $q->where('sort_order', 0);

                if ($closedDays !== []) {
                    $q->orWhereIn('day_of_week', $closedDays);
                }
            })
            ->delete();

        if ($rows !== []) {
            ClientBusinessHour::insert($rows);
        }
    }

    /**
     * Drop the separators people type out of a code that has to be comparable,
     * and upper-case it. Blank input stays null rather than becoming "".
     */
    private function canonical(mixed $value, string $stripPattern): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strtoupper((string) preg_replace($stripPattern, '', $value));
    }

    /**
     * Remove one stored logo, whichever disk it landed on. Takes the path and
     * disk rather than the client, so a replacement can be written first and the
     * file it superseded deleted afterwards.
     */
    private function deleteLogoFile(?string $path, ?string $disk): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $disk ??= 'public';
        app(StorageManager::class)->ensureDiskReady($disk);
        Storage::disk($disk)->delete($path);
    }
}
