<?php

namespace Tests\Feature\Offers;

use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Offers\Models\Offer;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Sending the offer.
 *
 * The properties that matter are about honesty: an offer is marked sent only
 * when the driver accepted it, a closed WhatsApp window is refused by us in
 * Romanian rather than by Meta thirty hours later, and a channel that cannot
 * carry a file says so instead of dropping the PDF quietly.
 */
class OfferSendTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ctx = $this->createWorkspaceContext();
    }

    private function fakeDriver(bool $fails = false): void
    {
        $driver = Mockery::mock(ChannelDriverInterface::class);

        if ($fails) {
            $driver->shouldReceive('send')->andThrow(new \RuntimeException('provider refused'));
        } else {
            $driver->shouldReceive('send')->andReturn('provider-id-1');
        }

        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);
    }

    private function conversation(string $channel, bool $windowOpen = true): Conversation
    {
        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => $channel,
            'status' => 'active',
            'display_name' => 'Acct',
            'phone_number_id' => '123456',
            'credentials' => [],
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);

        $conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => $channel,
            'type' => 'text',
            'body' => 'salut',
            'status' => 'delivered',
            'sent_at' => $windowOpen ? now()->subHour() : now()->subDays(3),
        ]);

        return $conversation->fresh();
    }

    private function offerFor(Conversation $conversation): Offer
    {
        $this->actingAs($this->ctx['user'])->post(route('client.offers.store'), [
            'contact_id' => $conversation->contact_id,
        ]);

        $offer = Offer::latest('id')->firstOrFail();

        $item = CatalogItem::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'type' => 'product',
            'name' => 'Cremă 50ml',
            'unit' => 'buc',
            'price_cents' => 24000,
        ]);

        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'contact_id' => $conversation->contact_id,
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        return $offer->fresh();
    }

    public function test_sending_marks_the_offer_sent_and_records_the_channel(): void
    {
        $conversation = $this->conversation('email');
        $offer = $this->offerFor($conversation);
        $this->fakeDriver();

        $this->actingAs($this->ctx['user'])
            ->post(route('client.offers.send', $offer->uuid), ['message' => 'Bună, ți-am pregătit oferta.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $offer->refresh();
        $this->assertSame('sent', $offer->status);
        $this->assertSame('email', $offer->channel);
        $this->assertSame($conversation->id, (int) $offer->conversation_id);
        $this->assertNotNull($offer->sent_at);
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->where('direction', 'out')->count());
    }

    public function test_a_send_the_provider_refused_does_not_mark_the_offer_sent(): void
    {
        $conversation = $this->conversation('email');
        $offer = $this->offerFor($conversation);
        $this->fakeDriver(fails: true);

        $this->actingAs($this->ctx['user'])
            ->post(route('client.offers.send', $offer->uuid), ['message' => 'Bună.'])
            ->assertRedirect()
            ->assertSessionHas('error');

        // The person must be able to try again rather than find a "sent" offer
        // the customer never received.
        $this->assertSame('draft', $offer->fresh()->status);
        $this->assertNull($offer->fresh()->sent_at);
    }

    public function test_a_closed_whatsapp_window_is_refused_by_us(): void
    {
        $conversation = $this->conversation('whatsapp', windowOpen: false);
        $offer = $this->offerFor($conversation);
        $this->fakeDriver();

        $this->actingAs($this->ctx['user'])
            ->post(route('client.offers.send', $offer->uuid), ['message' => 'Bună.'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('draft', $offer->fresh()->status);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('direction', 'out')->count());
    }

    public function test_an_offer_with_no_conversation_says_so(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.offers.store'), []);
        $offer = Offer::latest('id')->firstOrFail();
        $this->fakeDriver();

        $this->actingAs($this->ctx['user'])
            ->post(route('client.offers.send', $offer->uuid), ['message' => 'Bună.'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('draft', $offer->fresh()->status);
    }

    public function test_an_offer_already_sent_cannot_be_sent_again(): void
    {
        $conversation = $this->conversation('email');
        $offer = $this->offerFor($conversation);
        $offer->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $this->fakeDriver();

        $this->actingAs($this->ctx['user'])
            ->from(route('client.offers.show', $offer->uuid))
            ->post(route('client.offers.send', $offer->uuid), ['message' => 'Bună.'])
            ->assertSessionHasErrors('status');
    }

    public function test_another_firm_cannot_send_this_offer(): void
    {
        $conversation = $this->conversation('email');
        $offer = $this->offerFor($conversation);
        $intruder = $this->createWorkspaceContext();
        $this->fakeDriver();

        $this->actingAs($intruder['user'])
            ->post(route('client.offers.send', $offer->uuid), ['message' => 'Furat'])
            ->assertForbidden();

        $this->assertSame('draft', $offer->fresh()->status);
    }
}
