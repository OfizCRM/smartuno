<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Inbox\Services\MessengerDriver;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class InboxShareProductTest extends TestCase
{
    use RefreshDatabase;

    private function store(int $workspaceId): EcommerceStore
    {
        return EcommerceStore::create([
            'workspace_id' => $workspaceId,
            'platform' => 'shopify',
            'name' => 'Demo',
            'domain' => 'demo-'.$workspaceId.'.myshopify.com',
            'status' => 'connected',
            'external_meta' => ['currency' => 'USD'],
        ]);
    }

    private function product(int $workspaceId, int $storeId, array $attrs = []): EcommerceProduct
    {
        return EcommerceProduct::create(array_merge([
            'workspace_id' => $workspaceId,
            'store_id' => $storeId,
            'external_id' => (string) fake()->unique()->numberBetween(1000, 99999),
            'platform' => 'shopify',
            'name' => 'Blue Widget',
            'sku' => 'BW-1',
            'price' => 19.99,
            'inventory_quantity' => 7,
            'status' => 'active',
            'image_url' => 'https://cdn.example.com/widget.png',
            'raw' => ['handle' => 'blue-widget'],
        ], $attrs));
    }

    private function conversation(int $workspaceId, string $channel = 'whatsapp', array $creds = []): Conversation
    {
        $account = ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => $channel,
            'status' => 'active',
            'display_name' => 'Acct',
            'phone_number_id' => '123456',
            'credentials' => $creds,
        ]);
        $contact = Contact::create(['workspace_id' => $workspaceId, 'phone_e164' => '+447700900111']);

        return Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => 'PSID-123',
            'last_message_at' => now(),
        ]);
    }

    /** Open the WhatsApp 24h window by recording a recent inbound message. */
    private function openWindow(Conversation $conversation): void
    {
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'hi',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    private function fakeDriver(string $returns = 'wamid.TEST'): void
    {
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturn($returns);
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);
    }

    public function test_product_search_returns_only_workspace_products(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $store = $this->store($ws->id);
        $this->product($ws->id, $store->id, ['name' => 'Red Shoes', 'sku' => 'RS-9']);
        $this->product($ws->id, $store->id, ['name' => 'Green Hat', 'sku' => 'GH-2']);

        // A product in a different workspace must never leak.
        ['workspace' => $other] = $this->createWorkspaceContext();
        $otherStore = $this->store($other->id);
        $this->product($other->id, $otherStore->id, ['name' => 'Red Secret', 'sku' => 'X-1']);

        $res = $this->actingAs($user)->getJson(route('client.ecommerce.products.search', ['q' => 'Red']));

        $res->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Red Shoes')
            ->assertJsonPath('0.currency', 'USD');
    }

    public function test_share_product_sends_image_card_on_whatsapp(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $store = $this->store($ws->id);
        $product = $this->product($ws->id, $store->id);
        $conversation = $this->conversation($ws->id, 'whatsapp');
        $this->openWindow($conversation);
        $this->fakeDriver();

        $res = $this->actingAs($user)->postJson(
            route('client.inbox.share-product', $conversation),
            ['product_id' => $product->id],
        );

        $res->assertOk()->assertJsonPath('error', null);

        $message = Message::where('conversation_id', $conversation->id)->where('direction', 'out')->first();
        $this->assertNotNull($message);
        $this->assertSame('image', $message->type);
        $this->assertSame('sent', $message->status);
        $this->assertStringContainsString('Blue Widget', $message->body);
        $this->assertStringContainsString('BW-1', $message->body);
        // Currency symbol on the price and a clickable storefront URL.
        $this->assertStringContainsString('Price: $19.99', $message->body);
        $this->assertStringContainsString('https://demo-'.$ws->id.'.myshopify.com/products/blue-widget', $message->body);
        $this->assertSame('https://cdn.example.com/widget.png', $message->payload['link'] ?? null);
    }

    public function test_share_product_sends_image_card_on_messenger(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $store = $this->store($ws->id);
        $product = $this->product($ws->id, $store->id);
        $conversation = $this->conversation($ws->id, 'messenger');
        $this->fakeDriver();

        $res = $this->actingAs($user)->postJson(
            route('client.inbox.share-product', $conversation),
            ['product_id' => $product->id],
        );

        $res->assertOk();
        $message = Message::where('conversation_id', $conversation->id)->where('direction', 'out')->first();
        $this->assertSame('image', $message->type);
        $this->assertSame('https://cdn.example.com/widget.png', $message->payload['link'] ?? null);
        $this->assertStringContainsString('Blue Widget', $message->body);
        // The caption must NOT dump the raw image URL — the photo is the image.
        $this->assertStringNotContainsString('cdn.example.com', $message->body);
    }

    public function test_share_product_falls_back_to_text_without_photo(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $store = $this->store($ws->id);
        $product = $this->product($ws->id, $store->id, ['image_url' => null]);
        $conversation = $this->conversation($ws->id, 'messenger');
        $this->fakeDriver();

        $res = $this->actingAs($user)->postJson(
            route('client.inbox.share-product', $conversation),
            ['product_id' => $product->id],
        );

        $res->assertOk();
        $message = Message::where('conversation_id', $conversation->id)->where('direction', 'out')->first();
        $this->assertSame('text', $message->type);
        $this->assertStringContainsString('Blue Widget', $message->body);
    }

    public function test_messenger_driver_sends_photo_attachment_then_caption(): void
    {
        ['workspace' => $ws] = $this->createWorkspaceContext();
        $conversation = $this->conversation($ws->id, 'messenger', ['page_access_token' => 'TKN']);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'messenger',
            'type' => 'image',
            'body' => "🛍️ Blue Widget\nPrice: 19.99",
            'payload' => ['link' => 'https://cdn.example.com/widget.png', 'preview_url' => 'https://cdn.example.com/widget.png'],
            'status' => 'queued',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.ABC'], 200)]);

        $id = app(MessengerDriver::class)->send($message->fresh()->load('conversation.channelAccount'));

        $this->assertSame('mid.ABC', $id);
        Http::assertSentCount(2);
        // First: the product photo as an image attachment.
        Http::assertSent(fn ($req) => ($req->data()['message']['attachment']['payload']['url'] ?? null) === 'https://cdn.example.com/widget.png');
        // Then: the caption as text.
        Http::assertSent(fn ($req) => str_contains($req->data()['message']['text'] ?? '', 'Blue Widget'));
    }

    public function test_share_product_blocked_when_whatsapp_window_closed(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $store = $this->store($ws->id);
        $product = $this->product($ws->id, $store->id);
        $conversation = $this->conversation($ws->id, 'whatsapp'); // no inbound -> window closed
        $this->fakeDriver();

        $res = $this->actingAs($user)->postJson(
            route('client.inbox.share-product', $conversation),
            ['product_id' => $product->id],
        );

        $res->assertStatus(422);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
        ]);
    }

    public function test_share_product_rejects_other_workspace_product(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $conversation = $this->conversation($ws->id, 'messenger');

        ['workspace' => $other] = $this->createWorkspaceContext();
        $otherStore = $this->store($other->id);
        $foreign = $this->product($other->id, $otherStore->id);

        $this->fakeDriver();

        $res = $this->actingAs($user)->postJson(
            route('client.inbox.share-product', $conversation),
            ['product_id' => $foreign->id],
        );

        $res->assertNotFound();
    }

    // ─── the hand-written catalogue ──────────────────────────────────────
    //
    // Same button, second source. A firm with no webshop has no
    // ecommerce_products row at all, so without this branch the button is there
    // and does nothing for most of the customers this product is sold to.

    private function catalogItem(int $workspaceId, array $attrs = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'workspace_id' => $workspaceId,
            'type' => 'product',
            'name' => 'Cremă hidratantă 50ml',
            'code' => 'CR-1042',
            'unit' => 'buc',
            'price_cents' => 24000,
            'stock' => 24,
            'is_active' => true,
        ], $attrs));
    }

    public function test_a_catalogue_item_is_shared_as_text_on_whatsapp(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $item = $this->catalogItem($ws->id);
        $conversation = $this->conversation($ws->id, 'whatsapp');
        $this->openWindow($conversation);
        $this->fakeDriver();

        $res = $this->actingAs($user)->postJson(
            route('client.inbox.share-product', $conversation),
            ['product_id' => $item->id, 'source' => 'catalog'],
        );

        $res->assertOk()->assertJsonPath('error', null);

        $message = Message::where('conversation_id', $conversation->id)->where('direction', 'out')->first();
        $this->assertNotNull($message);
        // Text, not image: Stage 1 has no photos, and the existing image path
        // sends a public URL our private disk cannot provide.
        $this->assertSame('text', $message->type);
        $this->assertNull($message->payload);
        $this->assertSame('sent', $message->status);
        $this->assertStringContainsString('Cremă hidratantă 50ml', $message->body);
        $this->assertStringContainsString('CR-1042', $message->body);
        // Lei, from bani, in Romanian formatting — never "24000" and never "$".
        $this->assertStringContainsString('240', $message->body);
        $this->assertStringNotContainsString('24000', $message->body);
        $this->assertStringNotContainsString('$', $message->body);
    }

    public function test_a_catalogue_item_from_another_workspace_is_not_shared(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $conversation = $this->conversation($ws->id, 'messenger');

        ['workspace' => $other] = $this->createWorkspaceContext();
        $foreign = $this->catalogItem($other->id);

        $this->fakeDriver();

        $this->actingAs($user)->postJson(
            route('client.inbox.share-product', $conversation),
            ['product_id' => $foreign->id, 'source' => 'catalog'],
        )->assertNotFound();

        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_an_inactive_catalogue_item_is_not_shared(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $item = $this->catalogItem($ws->id, ['is_active' => false]);
        $conversation = $this->conversation($ws->id, 'messenger');
        $this->fakeDriver();

        $this->actingAs($user)->postJson(
            route('client.inbox.share-product', $conversation),
            ['product_id' => $item->id, 'source' => 'catalog'],
        )->assertNotFound();
    }

    public function test_the_source_defaults_to_the_shop_so_an_old_client_is_unaffected(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $store = $this->store($ws->id);
        $product = $this->product($ws->id, $store->id);
        // A catalogue row sharing the same id would be reachable if the branch
        // were chosen by anything other than the explicit source.
        $this->catalogItem($ws->id, ['name' => 'NU TREBUIE TRIMIS']);
        $conversation = $this->conversation($ws->id, 'messenger');
        $this->fakeDriver();

        $this->actingAs($user)->postJson(
            route('client.inbox.share-product', $conversation),
            ['product_id' => $product->id],
        )->assertOk();

        $message = Message::where('conversation_id', $conversation->id)->where('direction', 'out')->first();
        $this->assertStringContainsString('Blue Widget', $message->body);
        $this->assertStringNotContainsString('NU TREBUIE TRIMIS', $message->body);
    }
}
