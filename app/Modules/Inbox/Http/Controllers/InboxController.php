<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Events\ConversationAssigned;
use App\Events\MessageSent;
use App\Events\TypingChanged;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Email\Services\AttachmentStore;
use App\Modules\Inbox\Models\InboxLabel;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function __construct(
        private ChannelManager $channelManager,
        private StorageManager $storageManager,
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
        ]);

        $msgType = $validated['type'] ?? 'text';
        $msgPayload = $validated['payload'] ?? null;

        $files = array_merge(
            $request->hasFile('attachment') ? [$request->file('attachment')] : [],
            $request->file('attachments') ?? [],
        );

        // Email keeps its files on our own disk; the WhatsApp path below uploads
        // to Meta's Media API first, which an email has no use for.
        if ($files !== [] && $conversation->resolvedChannel() === 'email') {
            $store = app(AttachmentStore::class);
            $entries = [];
            $bytes = 0;

            foreach ($files as $file) {
                $entry = $store->put(
                    (string) $file->getClientOriginalName(),
                    (string) ($file->getMimeType() ?: 'application/octet-stream'),
                    (string) file_get_contents($file->getRealPath()),
                    // The cap is on the message, not the file: ten attachments of
                    // nine megabytes each is still a mail nobody can receive.
                    $bytes,
                );

                if (! $entry['stored']) {
                    // Refused rather than sent without it. The person picked these
                    // files; a mail that arrives quietly missing one is worse than
                    // one that does not leave. Nothing already written stays behind.
                    foreach ($entries as $orphan) {
                        $store->delete($orphan['path']);
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
            $storedPath = $this->storageManager->prefixedPath('message-media/'.$file->hashName());
            $this->storageManager->disk()->putFileAs(dirname($storedPath), $file, basename($storedPath));
            $previewUrl = $this->storageManager->disk()->url($storedPath);

            $msgPayload = array_merge($msgPayload ?? [], [
                'media_id' => $mediaId,
                'preview_url' => $previewUrl,
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
     * Share a connected-store product into the conversation as a rich image card
     * (product photo + caption) — WhatsApp sends one captioned image, Messenger /
     * Instagram send the photo as an attachment followed by the caption. Products
     * without a photo fall back to a plain text card. The product is looked up via
     * the query builder rather than the Ecommerce model so the Inbox stays
     * decoupled from that module (mirrors the hasEcommerceStore probe in show()).
     */
    public function shareProduct(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $validated = $request->validate(['product_id' => ['required', 'integer']]);
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

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

        abort_unless($product, 404, __('Product not found.'));

        // Product sharing builds a WhatsApp interactive payload, so it is only
        // ever meaningful there. Unknown channel is treated as not-WhatsApp and
        // falls through to the driver, which refuses honestly.
        $channel = $conversation->resolvedChannel();

        // Free-form messages need an open 24h session on WhatsApp.
        if ($channel === 'whatsapp' && ! $conversation->isWhatsappWindowOpen()) {
            return response()->json([
                'error' => __('WhatsApp 24-hour session is closed. Use an approved template to re-engage this contact.'),
            ], 422);
        }

        $storeMeta = json_decode($product->store_meta ?? '', true) ?: [];
        $currency = (string) ($storeMeta['currency'] ?? '');
        $url = $this->productShareUrl($product);

        // WhatsApp renders bold (*…*); other channels show it literally, so only bold there.
        $caption = $this->formatProductMessage($product, currency: $currency, url: $url, bold: $channel === 'whatsapp');
        $image = $product->image_url ?: null;

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
     * Checks payload.preview_url first, then downloads from WhatsApp Graph API,
     * caches to local storage, updates the message, and redirects.
     */
    public function serveMedia(Request $request, Conversation $conversation, Message $message): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorise($request, $conversation);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        $payload = $message->payload ?? [];

        // Already cached locally — verify the file still exists before redirecting
        if (! empty($payload['preview_url'])) {
            $storagePath = "message-media/{$message->id}";
            $disk = $this->storageManager->disk();
            $files = $disk->files($this->storageManager->prefixedPath('message-media'));
            $cached = collect($files)->first(fn ($f) => str_starts_with($f, $this->storageManager->prefixedPath($storagePath)));

            if ($cached && $disk->exists($cached)) {
                return redirect($disk->url($cached));
            }

            // File missing — clear stale preview_url and fall through to re-download
            $payload = array_merge($payload, ['preview_url' => null]);
            $message->update(['payload' => $payload]);
        }

        // Resolve media ID from raw WhatsApp webhook payload
        $type = $message->type ?? 'image';
        $mediaId = $payload[$type]['id'] ?? $payload['media_id'] ?? null;

        if (! $mediaId) {
            abort(404, __('No media available.'));
        }

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $client = CloudApiClient::forWorkspace($workspaceId);

        if (! $client) {
            abort(503, __('WhatsApp account not configured.'));
        }

        try {
            ['url' => $downloadUrl, 'mime_type' => $mimeType] = $client->getMediaUrl($mediaId);
            $bytes = $client->downloadMedia($downloadUrl);
            $ext = explode('/', $mimeType)[1] ?? 'bin';
            $ext = str_replace(['jpeg'], ['jpg'], $ext);
            $filename = "message-media/{$message->id}.{$ext}";

            $filename = $this->storageManager->prefixedPath($filename);
            $this->storageManager->disk()->put($filename, $bytes);
            $previewUrl = $this->storageManager->disk()->url($filename);

            // Cache for next request
            $message->update(['payload' => array_merge($payload, ['preview_url' => $previewUrl, 'mime_type' => $mimeType])]);

            return redirect($previewUrl);
        } catch (\Throwable $e) {
            abort(502, __('Could not fetch media: :error', ['error' => $e->getMessage()]));
        }
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
