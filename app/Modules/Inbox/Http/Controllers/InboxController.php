<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Events\ConversationAssigned;
use App\Events\MessageSent;
use App\Events\TypingChanged;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentImporter;
use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Inbox\Services\MessageMediaStore;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Notifications\ConversationHandoverNotification;
use App\Services\StorageManager;
use App\Support\Demo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class InboxController extends Controller
{
    public function __construct(
        private ChannelManager $channelManager,
        private StorageManager $storageManager,
        private MessageMediaStore $media,
    ) {}

    /** Filters the inbox list understands. Anything else in the query string is ignored. */
    private const LIST_FILTERS = ['folder', 'channel', 'label', 'account_id', 'search'];

    /**
     * The conversation list, built once.
     *
     * index() and show() both render it — the sidebar has to stay populated when
     * a conversation is opened — and they used to carry byte-similar copies of
     * this query. A filter added to one and missed in the other shows up as the
     * list silently changing the moment you click a row.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Conversation>
     */
    private function conversationList(int $workspaceId, ?int $userId, array $filters): LengthAwarePaginator
    {
        return Conversation::where('workspace_id', $workspaceId)
            // assignedUser is limited to id+name on purpose: the row only needs a
            // label, and the full user model carries things a prop must not.
            ->with(['contact', 'channelAccount', 'lastMessage', 'labels', 'assignedUser:id,name'])
            ->inboxFolder($filters['folder'] ?? null, $userId)
            ->inboxNarrowed($filters)
            ->orderByDesc('last_message_at')
            ->paginate(30)
            ->withQueryString();
    }

    /**
     * The numbers beside every filter.
     *
     * All three are computed inside the ACTIVE FOLDER and ignore the channel and
     * label selection, which is what makes them add up: pick "Toate" and the five
     * channel counts sum to the total. Narrowing them as well would make every
     * number move whenever any filter is touched, and a count that shifts under
     * you is worse than no count.
     *
     * Three queries, not one per row: a naive version costs ten plus one per
     * label on every page load.
     *
     * @return array{views: array<string, int>, channels: array<string, int>, labels: array<int, int>}
     */
    private function filterCounts(int $workspaceId, ?int $userId, ?string $folder): array
    {
        $active = "'".implode("','", Conversation::ACTIVE_STATUSES)."'";

        $views = DB::table('conversations')
            ->where('workspace_id', $workspaceId)
            // The query builder knows nothing about the model's soft deletes, so
            // without this the counts keep including threads the tenant deleted
            // while the list beside them does not.
            ->whereNull('deleted_at')
            ->selectRaw("
                SUM(status IN ($active)) as `all`,
                SUM(status IN ($active) AND assigned_user_id = ?) as mine,
                SUM(status IN ($active) AND assigned_user_id IS NULL) as unassigned,
                SUM(status IN ($active) AND unread_count > 0) as unread,
                SUM(status = 'pending') as pending,
                SUM(status = 'resolved') as resolved,
                SUM(status = 'snoozed') as snoozed
            ", [$userId])
            ->first();

        // Same folder predicate the list uses, so the per-channel and per-label
        // numbers describe the rows actually on screen.
        $inFolder = fn () => Conversation::where('conversations.workspace_id', $workspaceId)
            ->inboxFolder($folder, $userId);

        $channels = $inFolder()
            ->join('channel_accounts', 'channel_accounts.id', '=', 'conversations.channel_account_id')
            ->groupBy('channel_accounts.channel')
            ->selectRaw('channel_accounts.channel as channel, count(*) as aggregate')
            ->pluck('aggregate', 'channel');

        $labels = $inFolder()
            ->join('inbox_label_conversation as pivot', 'pivot.conversation_id', '=', 'conversations.id')
            ->groupBy('pivot.label_id')
            ->selectRaw('pivot.label_id as label_id, count(*) as aggregate')
            ->pluck('aggregate', 'label_id');

        return [
            'views' => array_map('intval', (array) $views),
            'channels' => $channels->map(fn ($n) => (int) $n)->all(),
            'labels' => $labels->map(fn ($n) => (int) $n)->all(),
        ];
    }

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->workspace_id;
        $userId = $request->user()->id;
        $filters = $request->only(self::LIST_FILTERS);

        $conversations = $this->conversationList($workspaceId, $userId, $filters);

        $labels = InboxLabel::where('workspace_id', $workspaceId)->orderBy('name')->get(['id', 'name', 'color']);
        $channelAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->orderBy('channel')
            ->orderBy('display_name')
            ->get(['id', 'channel', 'display_name', 'phone_number_id']);

        return Inertia::render('Inbox/Index', [
            'conversations' => $conversations,
            'filters' => $filters,
            'labels' => $labels,
            'channelAccounts' => $channelAccounts,
            'counts' => $this->filterCounts($workspaceId, $userId, $filters['folder'] ?? null),
        ]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $this->authorise($request, $conversation);

        $conversation->load(['contact', 'channelAccount', 'labels']);
        // The Notes tab shows how many there are before you open it.
        $conversation->loadCount('internalNotes');
        $messages = $conversation->messages()->with('conversation')->orderBy('sent_at')->get();

        // Mark as read
        $conversation->update(['unread_count' => 0]);

        // Align UI with WhatsApp session rules (inbound-only window; see Conversation::isWhatsappWindowOpen)
        $conversation->setAttribute(
            'is_whatsapp_window_open',
            $conversation->channelAccount?->channel !== 'whatsapp' || $conversation->isWhatsappWindowOpen(),
        );

        $workspaceId = $request->user()->workspace_id;
        $userId = $request->user()->id;
        $allLabels = InboxLabel::where('workspace_id', $workspaceId)->orderBy('name')->get(['id', 'name', 'color']);

        // Team members for agent assignment
        $teamMembers = User::where('workspace_id', $workspaceId)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        // WhatsApp approved templates for the template picker (used when 24h session is closed)
        $whatsappTemplates = $conversation->channelAccount?->channel === 'whatsapp'
            ? WhatsappTemplate::where('workspace_id', $workspaceId)
                ->where('status', 'APPROVED')
                ->orderBy('name')
                ->get(['id', 'name', 'language', 'components'])
            : collect();

        // Same builder the index uses, so the sidebar list cannot drift from the
        // one the user was looking at a click earlier.
        $filters = $request->only(self::LIST_FILTERS);
        $conversations = $this->conversationList($workspaceId, $userId, $filters);

        $channelAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->orderBy('channel')
            ->orderBy('display_name')
            ->get(['id', 'channel', 'display_name', 'phone_number_id']);

        // Whether to show the Orders tab (Ecommerce module). Queried directly to
        // avoid a cross-module model import; table may not exist if module removed.
        $hasEcommerceStore = Schema::hasTable('ecommerce_stores')
            && DB::table('ecommerce_stores')
                ->where('workspace_id', $workspaceId)
                ->where('status', 'connected')
                ->exists();

        // Whether the composer's "share a product" button has anything to offer.
        // Deliberately a second flag rather than a widening of the one above: that
        // one gates the Orders tab and has to keep meaning "a shop is connected",
        // while a firm with no shop at all can still share from a hand-written
        // catalogue. Same direct-query reasoning as above (no cross-module import,
        // table absent until the Catalog module's migration has run).
        $hasCatalog = $hasEcommerceStore || (Schema::hasTable('catalog_items')
            && DB::table('catalog_items')
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->exists());

        return Inertia::render('Inbox/Show', [
            'conversation' => $conversation,
            'messages' => $messages,
            'allLabels' => $allLabels,
            'conversations' => $conversations,
            'filters' => $filters,
            'teamMembers' => $teamMembers,
            'whatsappTemplates' => $whatsappTemplates,
            'channelAccounts' => $channelAccounts,
            'hasEcommerceStore' => $hasEcommerceStore,
            'hasCatalog' => $hasCatalog,
            'counts' => $this->filterCounts($workspaceId, $userId, $filters['folder'] ?? null),
        ]);
    }

    public function reply(Request $request, Conversation $conversation): JsonResponse|RedirectResponse
    {
        $this->authorise($request, $conversation);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4096'],
            'type' => ['nullable', 'in:text,template,image,document,video,audio'],
            'payload' => ['nullable', 'array'],
            // Email only. It belongs to the message rather than the thread: a
            // correspondent can rename a subject halfway through.
            'subject' => ['nullable', 'string', 'max:255'],
            // Allow-list of messaging media types (no HTML/SVG/executables).
            'attachment' => [
                'nullable', 'file', 'max:20480',
                'mimes:jpg,jpeg,png,webp,mp4,3gp,mov,mp3,aac,m4a,amr,ogg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt',
            ],
            // An email carries as many as fit; `attachment` above stays for the
            // single-file channels and for anything already posting that shape.
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => [
                'file', 'max:20480',
                'mimes:jpg,jpeg,png,webp,mp4,3gp,mov,mp3,aac,m4a,amr,ogg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt',
            ],
            // Files already in the library, sent without a trip through the
            // browser: the bytes are on our disk, so they are copied server-side.
            'document_uuids' => ['nullable', 'array', 'max:10'],
            'document_uuids.*' => ['string', 'uuid'],
        ]);

        $msgType = $validated['type'] ?? 'text';

        // The caller does not get to address a file. `payload` is validated only
        // as "an array", and the browser never sends these keys — it sends
        // template variables, or null. Left through, a plain-text reply to the
        // caller's OWN conversation could carry a hand-written attachments entry
        // naming any path on any disk, and AttachmentController would then serve
        // those bytes: both its checks pass, because the conversation and the
        // message really are the caller's. Only the file named is not.
        $msgPayload = Arr::except($validated['payload'] ?? [], [
            'attachments', 'media_id', 'preview_url', 'link', 'path', 'disk', 'media_path', 'media_disk',
        ]) ?: null;

        $files = array_merge(
            $request->hasFile('attachment') ? [$request->file('attachment')] : [],
            $request->file('attachments') ?? [],
        );

        $documentUuids = $validated['document_uuids'] ?? [];
        /** @var EloquentCollection<int, Document> $documents */
        $documents = $documentUuids === []
            ? new EloquentCollection
            : Document::where('workspace_id', $request->user()->workspace_id)
                ->whereIn('uuid', $documentUuids)
                ->get();

        // A document chosen from the library is copied straight across; the other
        // channels would need it uploaded to Meta's Media API first, which is not
        // wired up, so they say so instead of sending an empty message.
        if ($documents->isNotEmpty() && $conversation->resolvedChannel() !== 'email') {
            return response()->json(['error' => __('Documents can only be sent on email for now.')], 422);
        }

        // Email keeps its files on our own disk; the WhatsApp path below uploads
        // to Meta's Media API first, which an email has no use for.
        if (($files !== [] || $documents->isNotEmpty()) && $conversation->resolvedChannel() === 'email') {
            $store = app(AttachmentStore::class);
            $entries = [];
            $bytes = 0;

            foreach ($files as $file) {
                try {
                    $entry = $store->put(
                        (string) $file->getClientOriginalName(),
                        (string) ($file->getMimeType() ?: 'application/octet-stream'),
                        (string) file_get_contents($file->getRealPath()),
                        // The cap is on the message, not the file: ten attachments of
                        // nine megabytes each is still a mail nobody can receive.
                        $bytes,
                    );
                } catch (\Throwable $e) {
                    // A failed write throws now, and the cleanup below was wired
                    // only to the over-cap branch — so a throw on the third of
                    // five files stranded the first two on the tenant's disk,
                    // counted against their quota, referenced by nothing.
                    //
                    // Every entry here came back from put() this request, so it
                    // always carries a disk and there is no ?? to write: the
                    // orphan is deleted from the disk it was actually written
                    // to, which mid-migration is not necessarily the one a
                    // delete would otherwise guess at.
                    foreach ($entries as $orphan) {
                        $store->delete($orphan['path'], $orphan['disk']);
                    }

                    Log::error('Inbox attachment write failed', ['error' => $e->getMessage()]);

                    return response()->json(['error' => __('That file could not be stored. Please try again.')], 500);
                }

                if (! $entry['stored']) {
                    // Refused rather than sent without it. The person picked these
                    // files; a mail that arrives quietly missing one is worse than
                    // one that does not leave. Nothing already written stays behind.
                    foreach ($entries as $orphan) {
                        $store->delete($orphan['path'], $orphan['disk']);
                    }

                    return response()->json(['error' => __('That file is too large to send by email.')], 422);
                }

                $bytes += $entry['size'];
                $entries[] = $entry;
            }

            $importer = app(DocumentImporter::class);
            foreach ($documents as $document) {
                $entry = $importer->toAttachment($document, $bytes, AttachmentStore::MAX_MESSAGE_BYTES);

                if ($entry === null) {
                    foreach ($entries as $orphan) {
                        $store->delete($orphan['path'], $orphan['disk']);
                    }

                    return response()->json(['error' => __('That file is too large to send by email.')], 422);
                }

                $bytes += $entry['size'];
                $entries[] = $entry;
            }

            $msgPayload = array_merge($msgPayload ?? [], ['attachments' => $entries]);
            $validated['body'] = $validated['body'] ?: $entries[0]['name'];
        } elseif ($files !== []) {
            // Meta's Media API carries one file per message, so the other channels
            // cannot honour a second one and must say so instead of dropping it.
            if (count($files) > 1) {
                return response()->json(['error' => __('Only one file can be sent per message on this channel.')], 422);
            }

            $file = $files[0];
            $mimeType = $file->getMimeType() ?? 'application/octet-stream';

            // Derive type from MIME if not explicitly set
            if ($msgType === 'text') {
                $msgType = str_starts_with($mimeType, 'image/') ? 'image'
                    : (str_starts_with($mimeType, 'video/') ? 'video' : 'document');
            }

            // Upload to WhatsApp so we have a media_id for sending
            $client = CloudApiClient::forWorkspace($conversation->workspace_id);
            if (! $client) {
                return response()->json(['error' => __('No active WhatsApp account.')], 422);
            }

            $mediaId = $client->uploadMedia($file->getRealPath(), $mimeType);

            // The private disk, under this workspace, uuid-named, extension from
            // the DETECTED type. WhatsApp already has the bytes — uploadMedia()
            // above returned a media_id — so what is kept here exists only so
            // the agent can see in the thread what they sent, which is a reason
            // to store it privately rather than publish it.
            $stored = $this->media->putUpload((int) $conversation->workspace_id, $file);

            $msgPayload = array_merge($msgPayload ?? [], [
                'media_id' => $mediaId,
                'media_path' => $stored['path'],
                'media_disk' => $stored['disk'],
                'caption' => $validated['body'] ?? null,
                'filename' => $file->getClientOriginalName(),
            ]);

            // For image/document the 'body' shown in the chat is the caption or filename
            $validated['body'] = $validated['body'] ?? $file->getClientOriginalName();
        }

        // Require body for plain text messages
        if ($msgType === 'text' && empty($validated['body'])) {
            return back()->withErrors(['body' => __('Message body is required.')]);
        }

        // Enforce 24h window for WhatsApp — templates bypass the window restriction
        if ($conversation->channelAccount?->channel === 'whatsapp'
            && ! $conversation->isWhatsappWindowOpen()
            && $msgType !== 'template') {
            return back()->with('error', __('WhatsApp 24-hour session is closed. Use an approved template to re-engage this contact.'));
        }

        if (! empty($validated['subject'])) {
            $msgPayload = array_merge($msgPayload ?? [], ['subject' => $validated['subject']]);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => $conversation->resolvedChannel() ?? 'whatsapp',
            'type' => $msgType,
            'body' => $validated['body'],
            'payload' => $msgPayload,
            'status' => 'queued',
            'sent_by' => 'human',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        // Send via the channel driver
        // resolvedChannel(), not a fallback to whatsapp: an email thread routed
        // to the WhatsApp driver sends to the contact's phone number.
        $channel = $conversation->resolvedChannel();
        $sendError = null;
        try {
            if (! $channel) {
                throw new \RuntimeException(__('This conversation is not attached to a channel.'));
            }
            $driver = $this->channelManager->driver($channel);
            $messageId = $driver->send($message);
            $message->update(['status' => 'sent', 'provider_message_id' => $messageId]);
        } catch (\Throwable $e) {
            $sendError = $e->getMessage();
            Log::error('Inbox reply send failed', [
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'error' => $sendError,
            ]);
            $message->update(['status' => 'failed', 'error_json' => ['message' => $sendError]]);
        }

        $conversation->update(['last_message_at' => now()]);

        // SLA: set first_response_at on first outbound after inbound
        if ($conversation->last_inbound_at && ! $conversation->first_response_at) {
            $conversation->update(['first_response_at' => now()]);
        }

        // Re-load the relation so the broadcast event can resolve workspace_id
        $message->load('conversation');

        MessageSent::dispatch($message);

        if ($request->wantsJson()) {
            // Always return 200 so the UI can display the queued/failed bubble
            // immediately; the message status conveys delivery state.
            return response()->json([
                'message' => $message,
                'error' => $sendError,
            ]);
        }

        if ($sendError) {
            return back()->with('error', __('Message saved but failed to send: :error', ['error' => $sendError]));
        }

        return back()->with('success', __('Message sent.'));
    }

    /**
     * Share a product into the conversation as a rich image card (product photo +
     * caption) — WhatsApp sends one captioned image, Messenger / Instagram send
     * the photo as an attachment followed by the caption. Products without a photo
     * fall back to a plain text card.
     *
     * Two sources feed it. `ecommerce` is a row mirrored from a connected shop, and
     * is the default so that a caller predating the second source keeps its exact
     * behaviour. `catalog` is a row the firm typed by hand in the Catalog module —
     * always text, since Stage 1 catalogue items have no photo. Both are read
     * through the query builder rather than either module's model, so the Inbox
     * stays decoupled from both (mirrors the probes in show()).
     */
    public function shareProduct(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'source' => ['sometimes', 'in:ecommerce,catalog'],
        ]);
        $source = $validated['source'] ?? 'ecommerce';
        $workspaceId = (int) $request->user()->workspace_id;

        // Product sharing builds a WhatsApp interactive payload, so it is only
        // ever meaningful there. Unknown channel is treated as not-WhatsApp and
        // falls through to the driver, which refuses honestly.
        $channel = $conversation->resolvedChannel();

        // Each source resolves its own row and caption. Everything from the 24h
        // check downwards is shared, so the two cannot drift in how they send.
        if ($source === 'catalog') {
            $item = Schema::hasTable('catalog_items')
                ? DB::table('catalog_items')
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $validated['product_id'])
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->first()
                : null;

            abort_unless($item !== null, 404, __('Product not found.'));

            // Stage 1 catalogue items carry no photo (image_path exists as a column
            // but is never written), so this is always the plain text card.
            $caption = $this->formatCatalogMessage($item, bold: $channel === 'whatsapp');
            $image = null;
        } else {
            // Join the store for its currency (external_meta) and domain (URL building),
            // still without importing the Ecommerce model so the Inbox stays decoupled.
            $product = Schema::hasTable('ecommerce_products')
                ? DB::table('ecommerce_products as p')
                    ->leftJoin('ecommerce_stores as s', 's.id', '=', 'p.store_id')
                    ->where('p.workspace_id', $workspaceId)
                    ->where('p.id', $validated['product_id'])
                    ->select('p.*', 's.external_meta as store_meta', 's.domain as store_domain')
                    ->first()
                : null;

            abort_unless($product !== null, 404, __('Product not found.'));

            $storeMeta = json_decode($product->store_meta ?? '', true) ?: [];
            $currency = (string) ($storeMeta['currency'] ?? '');
            $url = $this->productShareUrl($product);

            // WhatsApp renders bold (*…*); other channels show it literally, so only bold there.
            $caption = $this->formatProductMessage($product, currency: $currency, url: $url, bold: $channel === 'whatsapp');
            $image = $product->image_url ?: null;
        }

        // Free-form messages need an open 24h session on WhatsApp.
        if ($channel === 'whatsapp' && ! $conversation->isWhatsappWindowOpen()) {
            return response()->json([
                'error' => __('WhatsApp 24-hour session is closed. Use an approved template to re-engage this contact.'),
            ], 422);
        }

        // Send the product photo as a real image on every channel (drivers handle the
        // per-channel rendering); fall back to text only when there is no photo.
        $useImage = (bool) $image;
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => $channel,
            'type' => $useImage ? 'image' : 'text',
            'body' => $caption,
            'payload' => $useImage ? ['link' => $image, 'preview_url' => $image, 'caption' => $caption] : null,
            'status' => 'queued',
            'sent_by' => 'human',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        $sendError = null;
        try {
            if (! $channel) {
                throw new \RuntimeException(__('This conversation is not attached to a channel.'));
            }
            $messageId = $this->channelManager->driver($channel)->send($message);
            $message->update(['status' => 'sent', 'provider_message_id' => $messageId]);
        } catch (\Throwable $e) {
            $sendError = $e->getMessage();
            Log::error('Inbox shareProduct send failed', [
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'error' => $sendError,
            ]);
            $message->update(['status' => 'failed', 'error_json' => ['message' => $sendError]]);
        }

        $conversation->update(['last_message_at' => now()]);
        if ($conversation->last_inbound_at && ! $conversation->first_response_at) {
            $conversation->update(['first_response_at' => now()]);
        }

        $message->load('conversation');
        MessageSent::dispatch($message);

        return response()->json(['message' => $message, 'error' => $sendError]);
    }

    /**
     * Format a product row into a message caption. The photo is sent as an image,
     * so the caption never includes the raw image URL.
     */
    private function formatProductMessage(object $product, string $currency = '', ?string $url = null, bool $bold = false): string
    {
        $name = trim((string) $product->name);
        $lines = [$bold ? '🛍️ *'.$name.'*' : '🛍️ '.$name];

        if ($product->price !== null && $product->price !== '') {
            // Trim trailing zeros so "9.99" stays but "10.00" shows as "10".
            $price = rtrim(rtrim(number_format((float) $product->price, 2, '.', ''), '0'), '.');
            $lines[] = 'Price: '.$this->currencyPrefix($currency).$price;
        }
        if (! empty($product->sku)) {
            $lines[] = 'SKU: '.$product->sku;
        }
        if (! empty($url)) {
            $lines[] = $url;
        }

        return implode("\n", $lines);
    }

    /**
     * Format a hand-written catalogue row into a message caption. Same shape as
     * formatProductMessage above — name first, then only the lines that carry
     * something, bold only where the channel renders it — but with no photo and
     * no storefront URL, because a catalogue item exists precisely for the firm
     * that has no shop to link to.
     *
     * Prices are stored in bani and written with Romanian separators: the customer
     * reads "1.250,00 lei". With the PHP defaults the same price arrives as
     * "1,250.00", which a Romanian reader takes for one and a quarter lei.
     *
     * A price of 0 is "not priced yet", the same thing the catalogue counts as
     * no_price, so it is left out rather than quoted to a customer as free.
     */
    private function formatCatalogMessage(object $item, bool $bold = false): string
    {
        $name = trim((string) $item->name);
        $lines = [$bold ? '🛍️ *'.$name.'*' : '🛍️ '.$name];

        if (! empty($item->code)) {
            $lines[] = __('Code').': '.$item->code;
        }
        if ((int) $item->price_cents > 0) {
            $lines[] = __('Price').': '.number_format((int) $item->price_cents / 100, 2, ',', '.').' lei';
        }
        if ($item->stock !== null) {
            $unit = trim((string) ($item->unit ?? ''));
            $lines[] = __('Stock').': '.(int) $item->stock.($unit !== '' ? ' '.$unit : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Render a currency as a symbol when known (e.g. "USD" → "$"), otherwise the
     * ISO code with a trailing space, or "" when no currency is set.
     */
    private function currencyPrefix(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return '';
        }

        $symbols = [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'INR' => '₹',
            'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$', 'BRL' => 'R$',
        ];

        return $symbols[$currency] ?? $currency.' ';
    }

    /**
     * Best-effort public storefront URL for a shared product, derived from the raw
     * platform payload. Shopify uses its published URL (or domain + handle); Woo
     * uses the product permalink. Returns null when none can be built.
     */
    private function productShareUrl(object $product): ?string
    {
        $raw = json_decode($product->raw ?? '', true);
        if (! is_array($raw)) {
            return null;
        }
        $domain = $product->store_domain ?? null;

        return match ($product->platform) {
            'shopify' => $raw['online_store_url']
                ?? (! empty($raw['handle']) && $domain ? "https://{$domain}/products/{$raw['handle']}" : null),
            'woocommerce' => ! empty($raw['permalink']) && filter_var($raw['permalink'], FILTER_VALIDATE_URL)
                ? $raw['permalink']
                : null,
            default => null,
        };
    }

    public function assign(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorise($request, $conversation);
        $request->validate(['user_id' => ['nullable', 'integer']]);

        $assignedTo = null;
        if ($request->user_id) {
            $assignedTo = User::where('workspace_id', $conversation->workspace_id)
                ->find($request->user_id);
            abort_unless($assignedTo, 422);
        }

        $conversation->update(['assigned_user_id' => $request->user_id]);
        ConversationAssigned::dispatch($conversation, $assignedTo);

        return back()->with('success', __('Conversation assigned.'));
    }

    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);
        $request->validate(['is_typing' => ['required', 'boolean']]);

        broadcast(new TypingChanged($conversation, $request->user(), (bool) $request->is_typing))->toOthers();

        return response()->json(['ok' => true]);
    }

    public function updateStatus(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorise($request, $conversation);
        $request->validate(['status' => ['required', 'in:open,pending,resolved,snoozed']]);

        $updates = ['status' => $request->status];
        if ($request->status === 'resolved' && ! $conversation->resolved_at) {
            $updates['resolved_at'] = now();
        }
        $conversation->update($updates);

        return back()->with('success', __('Status updated.'));
    }

    /**
     * Hide one thread for good.
     *
     * A soft delete: the row survives so an inbox row removed by mistake is
     * recoverable, and so inbound de-duplication can still see the messages that
     * were filed under it. Nothing is touched in the customer's own mailbox —
     * the settings page promises we neither delete nor move their mail, and they
     * read the same account from their phone.
     */
    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorise($request, $conversation);
        $conversation->delete();

        return redirect()
            ->route('client.inbox.index')
            ->with('success', __('Conversation deleted.'));
    }

    /**
     * Hide everything the user ticked.
     *
     * The workspace is a condition of the query rather than a check on each row:
     * an id belonging to another tenant simply does not match, so there is no
     * path where one is deleted because a check was forgotten.
     */
    public function destroyMany(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:200'],
            'uuids.*' => ['required', 'string', 'uuid'],
            // Set when the thread the caller has open is one of these: going
            // "back" would then land on a conversation that no longer resolves,
            // and route binding answers that with a 404 rather than a list.
            'to_index' => ['sometimes', 'boolean'],
        ]);

        $deleted = Conversation::where('workspace_id', $request->user()->workspace_id)
            ->whereIn('uuid', $data['uuids'])
            ->delete();

        $message = trans_choice(':count conversation(s) deleted.', $deleted, ['count' => $deleted]);

        return $request->boolean('to_index')
            ? redirect()->route('client.inbox.index')->with('success', $message)
            : back()->with('success', $message);
    }

    public function handover(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);
        $mode = $request->input('mode', 'human'); // 'human' or 'bot'

        $updates = ['assigned_to' => $mode];
        if ($mode === 'human' && ! $conversation->handover_at) {
            $updates['handover_at'] = now();
        }
        $conversation->update($updates);

        if ($mode === 'human') {
            $members = User::where('workspace_id', $conversation->workspace_id)->get();
            foreach ($members as $member) {
                $member->notify(new ConversationHandoverNotification($conversation, 'manual'));
            }
        }

        return response()->json(['ok' => true, 'assigned_to' => $mode]);
    }

    /**
     * Proxy / lazy-download inbound WhatsApp media.
     *
     * The cache lookup used to be a directory scan:
     *
     *     $files = $disk->files($this->storageManager->prefixedPath('message-media'));
     *     $cached = collect($files)->first(fn ($f) => str_starts_with($f, $prefix.$message->id));
     *
     * Three separate problems in one expression, and the first is a cross-tenant
     * read. `message-media/` is ONE FLAT DIRECTORY for the whole platform —
     * prefixedPath() applies an admin-wide prefix, not a per-workspace one — and
     * `messages` has no workspace_id and a single global auto-increment id. So
     * `str_starts_with($f, 'message-media/481')` matches `message-media/4812.jpg`,
     * which belongs to whatever firm message 4812 belongs to, and the method
     * then REDIRECTS the caller to its public URL. The two checks above are real
     * and both pass: the conversation is the caller's and the message is in it.
     * Only the file is somebody else's.
     *
     * It is not a rare alignment either. The outbound reply path and the mobile
     * one both store under a random hashName(), so a reply message NEVER has a
     * file named after its id — the scan starts at a guaranteed miss and takes
     * the first neighbour that matches.
     *
     * Second, it enumerated every firm's media on every image view; on R2 that
     * is a paginated ListObjectsV2 walk, 1000 keys a page, per picture.
     *
     * Third, the answer was already on the row. preview_url is the URL of the
     * very file being looked for.
     *
     * So: read the recorded path, check it belongs to this workspace, redirect.
     * Rows written before media_path existed fall back to preview_url itself,
     * which names the real object for all three legacy shapes (id-named inbound,
     * hash-named outbound, and shareProduct's external store URL) without a
     * listing, a scan or a backfill.
     */
    public function serveMedia(Request $request, Conversation $conversation, Message $message): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorise($request, $conversation);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        $payload = $message->payload ?? [];
        $workspaceId = (int) $conversation->workspace_id;

        // The path this workspace's own writes produced. Checked, not trusted:
        // the value lives in a JSON column that other code paths also write to,
        // and one directory segment is all that stands between reading our file
        // and reading the firm next door's.
        $recorded = is_string($payload['media_path'] ?? null) ? $payload['media_path'] : null;

        if ($recorded !== null && $this->media->addressableBy($recorded, $workspaceId)) {
            $contents = $this->media->isPrivate($payload['media_disk'] ?? null)
                ? $this->media->contents($recorded, (string) $payload['media_disk'])
                // Written before stage 5, so it is a key on the public disk.
                // Still served through here rather than by redirecting to it:
                // the file is world-readable either way, but the thread should
                // not be the thing that publishes the address.
                : $this->publicBytes($recorded);

            if ($contents !== null) {
                return $this->stream($recorded, $payload['filename'] ?? null, $contents);
            }

            // Gone from the disk. Drop the path so the row stops naming a file
            // that is not there, and fall through to fetching it again.
            $payload = array_merge($payload, ['media_path' => null, 'media_disk' => null, 'preview_url' => null]);
            $message->update(['payload' => $payload]);
        } elseif (! empty($payload['preview_url'])) {
            // Written before media_path existed. preview_url IS the address of
            // the file — it was minted by ->url() on the same disk at the same
            // moment the bytes were written — so there is nothing to look up.
            // shareProduct rows point at an external store image and are served
            // the same way, correctly, since that is where that picture lives.
            return redirect($payload['preview_url']);
        }

        // Resolve media ID from raw WhatsApp webhook payload
        $type = $message->type ?? 'image';
        $mediaId = $payload[$type]['id'] ?? $payload['media_id'] ?? null;

        if (! $mediaId) {
            abort(404, __('No media available.'));
        }

        // From the conversation, not the user: authorise() has already proved the
        // conversation is the caller's, and current_workspace_id is not a column
        // so the old idiom always fell through to the second half anyway.
        $client = CloudApiClient::forWorkspace($workspaceId);

        if (! $client) {
            abort(503, __('WhatsApp account not configured.'));
        }

        try {
            ['url' => $downloadUrl, 'mime_type' => $mimeType] = $client->getMediaUrl($mediaId);
            $bytes = $client->downloadMedia($downloadUrl);

            // Onto the PRIVATE disk, under this workspace, under a uuid, with an
            // extension from an allow-list rather than from the subtype string.
            // The old name was message-media/{$message->id}.{explode('/', $mime)[1]}
            // on the disk nginx publishes: a global sequential id anyone could
            // count through without logging in, and an extension taken verbatim
            // from a remote response.
            $stored = $this->media->put($workspaceId, $payload['filename'] ?? 'media', $mimeType, $bytes);

            // No preview_url any more. There is no address to record: the file
            // is not reachable except through this method, which is the point.
            $message->update(['payload' => array_merge($payload, [
                'media_path' => $stored['path'],
                'media_disk' => $stored['disk'],
                'preview_url' => null,
                'mime_type' => $mimeType,
            ])]);

            return $this->stream($stored['path'], $payload['filename'] ?? null, $bytes);
        } catch (\Throwable $e) {
            abort(502, __('Could not fetch media: :error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Bytes of a pre-stage-5 file, which is a key on the public disk.
     *
     * Deliberately not ->url(): see the note at the call site. Returns null the
     * same way the private store does, so one branch handles both.
     */
    private function publicBytes(string $path): ?string
    {
        $disk = $this->storageManager->disk();

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    /**
     * Hand a stored file to the browser.
     *
     * Inline for the things a thread has to render in place — a photo, a video,
     * a voice note — and a download for everything else, which is the same rule
     * AttachmentController applies and for the same reason: a type we have no
     * safe content type for is a type we do not let the browser interpret.
     *
     * nosniff and the sandboxed CSP are on both branches. The file arrived from
     * outside and the response comes from our own origin.
     */
    private function stream(string $path, ?string $name, string $contents): \Symfony\Component\HttpFoundation\Response
    {
        $mime = $this->media->mimeFor($path);
        $name = $name !== null && $name !== '' ? $name : basename($path);

        return response($contents, 200, [
            'Content-Type' => $mime ?? 'application/octet-stream',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                $mime === null ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
                $name,
                'fisier',
            ),
        ]);
    }

    /** Upload a media file to WhatsApp and return the media_id */
    public function uploadMedia(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $request->validate(['file' => ['required', 'file', 'max:16384']]);

        $file = $request->file('file');
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $client = CloudApiClient::forWorkspace($workspaceId);
        if (! $client) {
            return response()->json(['error' => __('No active WhatsApp account.')], 422);
        }

        try {
            $mediaId = $client->uploadMedia($file->getRealPath(), $mimeType);

            // Store a local copy so the UI can display a preview (WhatsApp media IDs are not URLs)
            $path = $this->storageManager->prefixedPath('template-media/'.$file->hashName());
            $this->storageManager->disk()->putFileAs(dirname($path), $file, basename($path));
            $previewUrl = $this->storageManager->disk()->url($path);

            return response()->json(['media_id' => $mediaId, 'mime_type' => $mimeType, 'preview_url' => $previewUrl]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Return approved WhatsApp templates for the workspace (JSON) */
    public function templates(Request $request): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $templates = WhatsappTemplate::where('workspace_id', $workspaceId)
            ->where('status', 'APPROVED')
            ->orderBy('name')
            ->get(['id', 'name', 'language', 'category', 'components']);

        return response()->json($templates);
    }

    /** Search contacts for the new-conversation modal (JSON) */
    public function contactSearch(Request $request): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $q = $request->input('q', '');

        $contacts = Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->when($q, fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('phone_e164', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->latest()
            ->limit(30)
            ->get(['id', 'first_name', 'last_name', 'phone_e164', 'email', 'country', 'avatar']);

        return response()->json($contacts->map(fn ($c) => array_merge($c->toArray(), [
            'avatar_url' => Demo::active() ? null : $c->avatar_url,
        ])));
    }

    /** Return active channel accounts for the workspace (JSON) */
    public function channelAccounts(Request $request): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $accounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->get(['id', 'channel', 'display_name', 'phone_number_id']);

        return response()->json($accounts);
    }

    /** Find or create a conversation, then redirect to it */
    public function startConversation(Request $request): RedirectResponse|JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'channel_account_id' => ['required', 'integer'],
            'body' => ['nullable', 'string', 'max:4096'],
        ]);

        $contact = Contact::where('workspace_id', $workspaceId)->findOrFail($validated['contact_id']);
        $channelAccount = ChannelAccount::where('workspace_id', $workspaceId)->findOrFail($validated['channel_account_id']);

        // Reuse the most recent open conversation for this contact + channel, or create a new one
        $conversation = Conversation::where('workspace_id', $workspaceId)
            ->where('contact_id', $contact->id)
            ->where('channel_account_id', $channelAccount->id)
            ->where('status', 'open')
            ->latest()
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'workspace_id' => $workspaceId,
                'contact_id' => $contact->id,
                'channel_account_id' => $channelAccount->id,
                'status' => 'open',
                'assigned_to' => 'human',
                'assigned_user_id' => $request->user()->id,
                'last_message_at' => now(),
            ]);
        }

        // Send the opening message if provided
        if (! empty($validated['body'])) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => $channelAccount->channel,
                'type' => 'text',
                'body' => $validated['body'],
                'status' => 'queued',
                'sent_by' => 'human',
                'user_id' => $request->user()->id,
                'sent_at' => now(),
            ]);

            try {
                $driver = $this->channelManager->driver($channelAccount->channel);
                $messageId = $driver->send($message);
                $message->update(['status' => 'sent', 'provider_message_id' => $messageId]);
            } catch (\Throwable $e) {
                Log::error('startConversation send failed', [
                    'conversation_id' => $conversation->id,
                    'channel' => $channelAccount->channel,
                    'error' => $e->getMessage(),
                ]);
                $message->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
            }

            $conversation->update(['last_message_at' => now()]);
            $message->load('conversation');
            MessageSent::dispatch($message);
        }

        return redirect()->route('client.inbox.show', $conversation);
    }

    private function authorise(Request $request, Conversation $conversation): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $conversation->workspace_id === (int) $workspaceId, 403);
    }
}
