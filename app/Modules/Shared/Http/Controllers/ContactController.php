<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ContactService;
use App\Services\StorageManager;
use App\Support\Demo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function __construct(
        private ContactService $contactService,
        private StorageManager $storageManager,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->workspace_id;

        $contacts = Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->when($request->search, function ($q) use ($request) {
                $like = '%'.addcslashes((string) $request->search, '%_\\').'%';
                $q->where(fn ($q) => $q
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$like])
                    ->orWhere('phone_e164', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('company', 'like', $like)
                    // The placeholder promises tags, so search them.
                    ->orWhereHas('tags', fn ($t) => $t->where('name', 'like', $like)));
            })
            ->when($request->tag, fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('name', $request->tag)))
            ->when(
                in_array($request->status, Contact::STATUSES, true),
                fn ($q) => $q->where('status', $request->status)
            )
            // Every segment card links here with ?segment=, and nothing read it —
            // "Vezi contactele" quietly returned the whole list instead.
            ->when($request->segment, fn ($q, $segmentId) => $q->whereHas(
                'segments', fn ($s) => $s->where('segments.id', $segmentId)
            ))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $tags = ContactTag::where('workspace_id', $workspaceId)->orderBy('name')->get();
        $segments = Segment::where('workspace_id', $workspaceId)->where('type', 'static')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'tags' => $tags,
            'segments' => $segments,
            'filters' => $request->only('search', 'tag', 'segment', 'status'),
            'activity' => $this->activityFor($workspaceId, $contacts->pluck('id')->all()),
            'statusCounts' => $this->statusCounts($workspaceId),
        ]);
    }

    /**
     * How many contacts sit in each state, for the chips above the list.
     *
     * Deliberately unfiltered by the search box: the chips are how you narrow,
     * so a number that moved while you typed would be describing a list you had
     * already left behind. One grouped query, on the (workspace_id, status) index.
     *
     * @return array<string, int>
     */
    private function statusCounts(int $workspaceId): array
    {
        $counts = DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');

        $out = ['all' => (int) $counts->sum()];
        foreach (Contact::STATUSES as $status) {
            $out[$status] = (int) ($counts[$status] ?? 0);
        }

        return $out;
    }

    /**
     * Which channels each contact has actually written on, and when they last did.
     *
     * The list used to show the three opt_in_* flags under a "Channels" heading.
     * Those answer "may we message them", not "where do they talk to us" — and
     * their defaults make them close to meaningless: opt_in_email defaults to
     * true for everyone, and the CSV import sets the WhatsApp and SMS flags from
     * the mere presence of a phone number.
     *
     * "Last seen" comes from the same query. contacts.last_seen_at exists as a
     * column but nothing in the application ever writes it, so a list built on it
     * would show a dash for every contact in a real workspace.
     *
     * One grouped query for the whole page rather than two per row.
     *
     * @param  array<int, int>  $contactIds
     * @return array<int, array{channels: array<int, string>, last_at: string|null}>
     */
    private function activityFor(int $workspaceId, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $rows = DB::table('conversations')
            ->leftJoin('channel_accounts', 'channel_accounts.id', '=', 'conversations.channel_account_id')
            ->where('conversations.workspace_id', $workspaceId)
            // The query builder does not apply the model's soft delete, and a
            // thread deleted from the inbox must not reappear on the contact.
            ->whereNull('conversations.deleted_at')
            ->whereIn('conversations.contact_id', $contactIds)
            ->groupBy('conversations.contact_id', 'channel_accounts.channel')
            ->selectRaw('conversations.contact_id as contact_id, channel_accounts.channel as channel, MAX(conversations.last_message_at) as last_at')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->contact_id;
            $out[$id] ??= ['channels' => [], 'last_at' => null];

            if ($row->channel && ! in_array($row->channel, $out[$id]['channels'], true)) {
                $out[$id]['channels'][] = $row->channel;
            }
            if ($row->last_at && ($out[$id]['last_at'] === null || $row->last_at > $out[$id]['last_at'])) {
                $out[$id]['last_at'] = $row->last_at;
            }
        }

        return $out;
    }

    public function bulkImport(Request $request): Response
    {
        return Inertia::render('Contacts/BulkImport', $this->bulkImportProps($request));
    }

    /**
     * @return array{tags: Collection, segments: Collection}
     */
    private function bulkImportProps(Request $request): array
    {
        $workspaceId = $request->user()->workspace_id;

        return [
            'tags' => ContactTag::where('workspace_id', $workspaceId)->orderBy('name')->get(),
            'segments' => Segment::where('workspace_id', $workspaceId)
                ->where('type', 'static')
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    public function show(Request $request, Contact $contact): Response
    {
        $this->authoriseContact($request, $contact);

        $workspaceId = $request->user()->workspace_id;
        // channelAccount was missing, so every conversation on this page rendered
        // as "unknown channel" — the component has always read it.
        $contact->load([
            'tags',
            'segments',
            'conversations' => fn ($q) => $q
                ->with(['channelAccount:id,channel,display_name', 'messages' => fn ($q) => $q->latest('sent_at')->limit(5)])
                ->latest('last_message_at')
                ->limit(10),
        ]);

        $staticSegments = Segment::where('workspace_id', $workspaceId)->where('type', 'static')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Contacts/Show', [
            'contact' => $contact,
            'staticSegments' => $staticSegments,
            'allTags' => ContactTag::where('workspace_id', $workspaceId)->orderBy('name')->get(['id', 'name', 'color']),
            'summary' => $this->summaryFor($workspaceId, $contact),
            'activity' => $this->contactTimeline($workspaceId, $contact),
        ]);
    }

    /**
     * The numbers in the header of a contact record.
     *
     * Deliberately only what can be answered honestly: how much they have spent,
     * how many conversations they have had, and when the last one was. There is
     * no quotes module and no appointments module in this codebase, so the two
     * cards the design had for those are not here.
     *
     * @return array{lifetime_value: string|null, lifetime_currency: string|null, conversations: int, last_at: string|null}
     */
    private function summaryFor(int $workspaceId, Contact $contact): array
    {
        // Written by the Ecommerce enricher when a store is connected and synced;
        // absent for every workspace without one, which is most of them.
        $custom = $contact->custom_fields ?? [];

        $last = DB::table('conversations')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->where('contact_id', $contact->id)
            ->max('last_message_at');

        return [
            'lifetime_value' => $custom['lifetime_value'] ?? null,
            'lifetime_currency' => $custom['lifetime_currency'] ?? null,
            'conversations' => (int) DB::table('conversations')
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->where('contact_id', $contact->id)
                ->count(),
            'last_at' => $last,
        ];
    }

    /**
     * What has happened with this contact, newest first.
     *
     * conversation_activities has no contact_id — it hangs off a conversation —
     * so this joins through conversations. There is no index for that shape; at
     * the volumes this product sells into it is a small filesort, and capping at
     * 30 keeps it that way.
     *
     * @return array<int, array{type: string, channel: string|null, at: string, actor: string|null, meta: array<string, mixed>}>
     */
    private function contactTimeline(int $workspaceId, Contact $contact): array
    {
        return DB::table('conversation_activities as a')
            ->join('conversations as c', 'c.id', '=', 'a.conversation_id')
            ->leftJoin('channel_accounts as ca', 'ca.id', '=', 'c.channel_account_id')
            // Who did it. Without the name every line reads "Sistem", and the
            // sentences that interpolate an actor read as a placeholder.
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.workspace_id', $workspaceId)
            ->where('c.contact_id', $contact->id)
            ->orderByDesc('a.created_at')
            ->limit(30)
            ->get(['a.type as type', 'a.meta as meta', 'a.created_at as at', 'ca.channel as channel', 'u.name as actor'])
            ->map(fn ($row) => [
                'type' => $row->type,
                'channel' => $row->channel,
                'at' => $row->at,
                'actor' => $row->actor,
                'meta' => json_decode((string) $row->meta, true) ?: [],
            ])
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->workspace_id;
        $validated = $request->validate([
            'phone_e164' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'country' => ['nullable', 'string', 'max:4'],
            'language' => ['nullable', 'string', 'max:8'],
            'opt_in_whatsapp' => ['boolean'],
            'opt_in_sms' => ['boolean'],
            'opt_in_email' => ['boolean'],
            'company' => ['nullable', 'string', 'max:191'],
            'job_title' => ['nullable', 'string', 'max:128'],
            // Deliberately unvalidated beyond a length: the Romanian CUI checksum
            // in App\Rules\ValidCui would reject a foreign VAT number, and a field
            // that refuses a correct value is worse than one that accepts a typo.
            'tax_id' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'birthday' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(Contact::STATUSES)],
            'segment_ids' => ['nullable', 'array'],
            'segment_ids.*' => ['integer', Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static'))],
        ]);

        $segmentIds = $validated['segment_ids'] ?? [];
        unset($validated['segment_ids']);

        $contact = $this->contactService->upsert($workspaceId, array_merge($validated, ['source' => 'manual']));

        if ($segmentIds) {
            $contact->segments()->syncWithoutDetaching($segmentIds);
            Segment::whereIn('id', $segmentIds)->each(fn ($s) => $s->update(['contact_count' => $s->contacts()->count()]));
        }

        return back()->with('success', __('Contact saved.'));
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);
        $workspaceId = $request->user()->workspace_id;
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'email' => ['nullable', 'email', 'max:191'],
            'country' => ['nullable', 'string', 'max:4'],
            'language' => ['nullable', 'string', 'max:8'],
            'opt_in_whatsapp' => ['boolean'],
            'opt_in_sms' => ['boolean'],
            'opt_in_email' => ['boolean'],
            'company' => ['nullable', 'string', 'max:191'],
            'job_title' => ['nullable', 'string', 'max:128'],
            // Deliberately unvalidated beyond a length: the Romanian CUI checksum
            // in App\Rules\ValidCui would reject a foreign VAT number, and a field
            // that refuses a correct value is worse than one that accepts a typo.
            'tax_id' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'birthday' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(Contact::STATUSES)],
            'segment_ids' => ['nullable', 'array'],
            'segment_ids.*' => ['integer', Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static'))],
            'tag_names' => ['nullable', 'array', 'max:20'],
            'tag_names.*' => ['string', 'max:64'],
        ]);

        $segmentIds = $validated['segment_ids'] ?? null;
        $tagNames = $validated['tag_names'] ?? null;
        unset($validated['segment_ids'], $validated['tag_names']);

        // custom_fields is deliberately NOT accepted here. It holds instagram_psid
        // and messenger_psid, which the two drivers use to match an incoming DM to
        // an existing contact, and this method replaces the whole JSON blob — a
        // form that posted it would fork every repeat sender into a new contact,
        // silently, days later.
        $contact->update($validated);

        if ($tagNames !== null) {
            $ids = [];
            foreach (array_filter(array_map('trim', $tagNames)) as $name) {
                $ids[] = ContactTag::firstOrCreate(
                    ['workspace_id' => $workspaceId, 'name' => $name],
                    ['color' => '#6366f1'],
                )->id;
            }
            $contact->tags()->sync($ids);
        }

        if ($segmentIds !== null) {
            $oldSegmentIds = $contact->segments()->where('type', 'static')->pluck('segments.id')->toArray();
            $contact->segments()->sync($segmentIds);
            $affectedIds = array_unique(array_merge($oldSegmentIds, $segmentIds));
            Segment::whereIn('id', $affectedIds)->each(fn ($s) => $s->update(['contact_count' => $s->contacts()->count()]));
        }

        return back()->with('success', __('Contact updated.'));
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);
        $contact->delete();

        return back()->with('success', __('Contact deleted.'));
    }

    public function uploadAvatar(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ]);

        // Delete old stored avatar if it's not an external URL
        if ($contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
            $this->storageManager->disk()->delete($contact->avatar);
        }

        // The three lines this replaces disagreed with each other about where
        // the file was:
        //
        //     $path = $this->storageManager->prefixedPath('contact-avatars/'.$file->hashName());
        //     $this->storageManager->disk()->putFileAs('contact-avatars', $file, basename($path));
        //     $contact->update(['avatar' => $path]);
        //
        // The write went to `contact-avatars/…`, the column recorded
        // `<prefix>/contact-avatars/…`. With no directory prefix configured the
        // two strings are identical and nothing is visibly wrong, which is why
        // it has survived — an admin setting a prefix, or moving to a bucket
        // that has one, breaks every avatar uploaded after that point and
        // leaves the files themselves orphaned outside the prefix.
        //
        // storeImageUpload() prefixes once, on the path it both writes to and
        // returns, so the two cannot drift again.
        $stored = $this->storageManager->storeImageUpload($request->file('avatar'), 'contact-avatars');

        if ($stored === null) {
            return back()->withErrors(['avatar' => __('This image could not be processed safely.')]);
        }

        $contact->update(['avatar' => $stored['path']]);

        return back()->with('success', __('Avatar updated.'));
    }

    public function deleteAvatar(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);

        if ($contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
            $this->storageManager->disk()->delete($contact->avatar);
        }

        $contact->update(['avatar' => null]);

        return back()->with('success', __('Avatar removed.'));
    }

    public function import(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->workspace_id;
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        // Detect delimiter from the first line (comma, semicolon, or tab).
        $firstLine = fgets($handle) ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();
        rewind($handle);

        $headers = null;
        $data = [];
        $limit = 10000;

        while (($line = fgetcsv($handle, null, $delimiter)) !== false && count($data) < $limit) {
            if ($headers === null) {
                // Strip a UTF-8 BOM so the first header matches field aliases.
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
                $headers = array_map('trim', $line);

                continue;
            }
            // Pad or truncate ragged rows instead of dropping them.
            $line = array_pad(array_slice($line, 0, count($headers)), count($headers), null);
            $data[] = array_combine($headers, $line);
        }
        fclose($handle);

        if ($headers === null || empty($data)) {
            return back()->withErrors(['file' => __('The CSV file appears to be empty or has no valid rows.')]);
        }

        $stats = $this->contactService->bulkImport($workspaceId, $data);

        return back()->with('success', __('Imported: :created created, :updated updated, :skipped skipped.', ['created' => $stats['created'], 'updated' => $stats['updated'], 'skipped' => $stats['skipped']]));
    }

    /**
     * Chunked JSON import used by the CSV import wizard. Rows arrive already
     * mapped to canonical field names; returns per-chunk stats for progress UI.
     * Optional tag_names / segment_ids are applied to every imported contact.
     */
    public function importRows(Request $request): JsonResponse
    {
        $workspaceId = (int) ($request->user()->workspace_id);

        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*' => ['array'],
            'tag_names' => ['nullable', 'array', 'max:20'],
            'tag_names.*' => ['string', 'max:64'],
            'segment_ids' => ['nullable', 'array', 'max:20'],
            'segment_ids.*' => [
                'integer',
                Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static')),
            ],
        ]);

        return response()->json($this->contactService->bulkImport(
            $workspaceId,
            $validated['rows'],
            tagNames: $validated['tag_names'] ?? [],
            segmentIds: $validated['segment_ids'] ?? [],
        ));
    }

    public function bulkStore(Request $request): Response
    {
        $workspaceId = (int) ($request->user()->workspace_id);

        $validated = $request->validate([
            'rows' => ['required', 'array', 'max:500'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.phone_e164' => ['nullable', 'string', 'max:20'],
            'rows.*.tag_id' => [
                'nullable',
                'integer',
                Rule::exists('contact_tags', 'id')->where('workspace_id', $workspaceId),
            ],
            'rows.*.segment_id' => [
                'nullable',
                'integer',
                Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static')),
            ],
        ]);

        $rows = array_values(array_filter(
            $validated['rows'],
            fn (array $r) => isset($r['phone_e164']) && trim((string) $r['phone_e164']) !== ''
        ));

        if ($rows === []) {
            throw ValidationException::withMessages([
                'rows' => __('Add at least one row with a phone number in international format (e.g. +1…).'),
            ]);
        }

        $stats = $this->contactService->importGridRows($workspaceId, $rows);

        $request->session()->flash(
            'success',
            __('Bulk import finished: :created created, :updated updated, :skipped skipped.', [
                'created' => $stats['created'],
                'updated' => $stats['updated'],
                'skipped' => $stats['skipped'],
            ])
        );

        return Inertia::render('Contacts/BulkImport', $this->bulkImportProps($request));
    }

    public function bulkTags(Request $request): RedirectResponse
    {
        $workspaceId = (int) ($request->user()->workspace_id);

        $validated = $request->validate([
            'uuids' => ['required', 'array', 'max:500'],
            'uuids.*' => ['string', 'uuid'],
            'action' => ['required', Rule::in(['add', 'remove'])],
            'tag_names' => ['required', 'array', 'min:1', 'max:20'],
            'tag_names.*' => ['string', 'max:64'],
        ]);

        $contactIds = Contact::where('workspace_id', $workspaceId)
            ->whereIn('uuid', $validated['uuids'])
            ->pluck('id');

        $tagNames = array_values(array_unique(array_filter(array_map('trim', $validated['tag_names']))));

        if ($validated['action'] === 'add') {
            $tagIds = collect($tagNames)
                ->map(fn (string $name) => ContactTag::firstOrCreate(['workspace_id' => $workspaceId, 'name' => $name])->id);

            $existing = DB::table('contact_tag_pivot')
                ->whereIn('contact_id', $contactIds)
                ->whereIn('tag_id', $tagIds)
                ->get(['contact_id', 'tag_id'])
                ->map(fn ($p) => $p->contact_id.':'.$p->tag_id)
                ->flip();

            $inserts = [];
            foreach ($contactIds as $contactId) {
                foreach ($tagIds as $tagId) {
                    if (! isset($existing[$contactId.':'.$tagId])) {
                        $inserts[] = ['contact_id' => $contactId, 'tag_id' => $tagId];
                    }
                }
            }
            if ($inserts !== []) {
                DB::table('contact_tag_pivot')->insert($inserts);
            }
        } else {
            $tagIds = ContactTag::where('workspace_id', $workspaceId)->whereIn('name', $tagNames)->pluck('id');
            DB::table('contact_tag_pivot')
                ->whereIn('contact_id', $contactIds)
                ->whereIn('tag_id', $tagIds)
                ->delete();
        }

        return back()->with('success', trans_choice('Tags updated for :count contact(s).', $contactIds->count()));
    }

    public function bulkSegments(Request $request): RedirectResponse
    {
        $workspaceId = (int) ($request->user()->workspace_id);

        $validated = $request->validate([
            'uuids' => ['required', 'array', 'max:500'],
            'uuids.*' => ['string', 'uuid'],
            'action' => ['required', Rule::in(['add', 'remove'])],
            'segment_ids' => ['required', 'array', 'min:1', 'max:20'],
            'segment_ids.*' => [
                'integer',
                Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static')),
            ],
        ]);

        $contactIds = Contact::where('workspace_id', $workspaceId)
            ->whereIn('uuid', $validated['uuids'])
            ->pluck('id');

        $segmentIds = array_values(array_unique($validated['segment_ids']));

        if ($validated['action'] === 'add') {
            $existing = DB::table('segment_contact')
                ->whereIn('contact_id', $contactIds)
                ->whereIn('segment_id', $segmentIds)
                ->get(['contact_id', 'segment_id'])
                ->map(fn ($p) => $p->contact_id.':'.$p->segment_id)
                ->flip();

            $inserts = [];
            foreach ($contactIds as $contactId) {
                foreach ($segmentIds as $segmentId) {
                    if (! isset($existing[$contactId.':'.$segmentId])) {
                        $inserts[] = ['contact_id' => $contactId, 'segment_id' => $segmentId];
                    }
                }
            }
            if ($inserts !== []) {
                DB::table('segment_contact')->insert($inserts);
            }
        } else {
            DB::table('segment_contact')
                ->whereIn('contact_id', $contactIds)
                ->whereIn('segment_id', $segmentIds)
                ->delete();
        }

        Segment::whereIn('id', $segmentIds)->each(fn ($s) => $s->update(['contact_count' => $s->contacts()->count()]));

        return back()->with('success', trans_choice('Segments updated for :count contact(s).', $contactIds->count()));
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->workspace_id;
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'max:500'],
            'uuids.*' => ['string', 'uuid'],
        ]);

        $deleted = Contact::where('workspace_id', $workspaceId)
            ->whereIn('uuid', $validated['uuids'])
            ->delete();

        return back()->with('success', trans_choice(':count contact(s) deleted.', $deleted));
    }

    public function export(Request $request): HttpResponse
    {
        $workspaceId = $request->user()->workspace_id;

        $contacts = Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->when($request->uuids, fn ($q) => $q->whereIn('uuid', explode(',', $request->uuids)))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%'.$request->search.'%')
                    ->orWhere('last_name', 'like', '%'.$request->search.'%')
                    ->orWhere('phone_e164', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            }))
            ->latest()
            ->get();

        $headers = ['First Name', 'Last Name', 'Phone', 'Email', 'Tags', 'Opt-in WhatsApp', 'Opt-in SMS', 'Opt-in Email', 'Created At'];
        $rows = $contacts->map(fn ($c) => [
            Demo::name($c->first_name) ?? '',
            Demo::name($c->last_name) ?? '',
            Demo::phone($c->phone_e164) ?? '',
            Demo::email($c->email) ?? '',
            $c->tags->pluck('name')->join(', '),
            $c->opt_in_whatsapp ? 'yes' : 'no',
            $c->opt_in_sms ? 'yes' : 'no',
            $c->opt_in_email ? 'yes' : 'no',
            $c->created_at?->toDateTimeString() ?? '',
        ]);

        $csv = collect([$headers])->merge($rows)->map(fn ($row) => collect($row)->map(fn ($v) => '"'.str_replace('"', '""', $v).'"')->join(',')
        )->join("\n");

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contacts-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    private function authoriseContact(Request $request, Contact $contact): void
    {
        $workspaceId = $request->user()->workspace_id;
        abort_unless((int) $contact->workspace_id === (int) $workspaceId, 403);
    }
}
