<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A conversation must be sent on the channel it actually belongs to.
 *
 * Campaign mirroring creates conversations with a NULL channel_account_id
 * whenever the workspace has no ChannelAccount for that channel — and no email
 * or SMS account can be created at all, so every email campaign produces one.
 * Every caller closed that gap with `?? 'whatsapp'`, which routed an email
 * thread to the WhatsApp driver and sent the agent's reply to the contact's
 * phone number.
 */
class ChannelRoutingTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function account(string $channel): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => $channel,
            'provider' => 'meta',
            'status' => 'active',
            'display_name' => strtoupper($channel),
        ]);
    }

    private function conversation(?ChannelAccount $account, ?string $messageChannel = null): Conversation
    {
        $conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel_account_id' => $account?->id,
            'contact_id' => Contact::factory()->create([
                'workspace_id' => $this->ctx['workspace']->id,
                'phone_e164' => '+4072'.random_int(1000000, 9999999),
                'email' => 'client'.random_int(1, 99999).'@exemplu.ro',
            ])->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        if ($messageChannel) {
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => $messageChannel,
                'type' => 'text',
                'body' => 'campanie',
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }

        return $conversation;
    }

    public function test_an_account_less_email_thread_resolves_to_email_not_whatsapp(): void
    {
        $conversation = $this->conversation(null, 'email');

        $this->assertSame('email', $conversation->fresh()->resolvedChannel());
    }

    public function test_a_conversation_with_an_account_uses_it(): void
    {
        $conversation = $this->conversation($this->account('instagram'), 'instagram');

        $this->assertSame('instagram', $conversation->fresh()->resolvedChannel());
    }

    public function test_a_conversation_with_nothing_at_all_resolves_to_nothing(): void
    {
        $this->assertNull($this->conversation(null)->fresh()->resolvedChannel());
    }

    public function test_replying_to_an_email_thread_is_refused_instead_of_going_out_over_whatsapp(): void
    {
        // A workspace that also has WhatsApp — which is exactly when the old
        // fallback stopped failing and started misdelivering.
        $this->account('whatsapp');
        $conversation = $this->conversation(null, 'email');

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.reply', $conversation->uuid), ['body' => 'Bună ziua, revin cu detalii.']);

        $response->assertOk();

        $message = Message::where('conversation_id', $conversation->id)->latest('id')->first();

        // The row is kept as a failed outbound so the agent can see it did not go,
        // rather than silently leaving over the wrong channel.
        $this->assertSame('failed', $message->status);
        $this->assertSame('email', $message->channel);
        $this->assertNotNull($response->json('error'));
    }

    public function test_a_reply_on_a_real_whatsapp_thread_still_routes_to_whatsapp(): void
    {
        $conversation = $this->conversation($this->account('whatsapp'), 'whatsapp');

        // An inbound message, or the 24-hour window check redirects before the
        // controller ever reaches the driver.
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Bună ziua',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.reply', $conversation->uuid), ['body' => 'Salut!'])
            ->assertOk();

        $message = Message::where('conversation_id', $conversation->id)->latest('id')->first();

        // No WhatsApp credentials in a test workspace, so it fails at the driver —
        // but on the WhatsApp driver, which is the point.
        $this->assertSame('whatsapp', $message->channel);
    }

    public function test_the_email_filter_finds_account_less_email_threads(): void
    {
        $email = $this->conversation(null, 'email');
        $whatsapp = $this->conversation($this->account('whatsapp'), 'whatsapp');

        $response = $this->actingAs($this->ctx['user'])->get(route('client.inbox.index', ['channel' => 'email']));
        $response->assertOk();
        $ids = array_column($response->viewData('page')['props']['conversations']['data'], 'id');

        // Before, this filtered through a channel_account that cannot exist for
        // email, so the Email row returned nothing for ever.
        $this->assertSame([$email->id], $ids);
        $this->assertNotContains($whatsapp->id, $ids);
    }

    public function test_the_email_filter_does_not_reach_another_workspace(): void
    {
        $this->conversation(null, 'email');

        $other = $this->createWorkspaceContext();
        $theirContact = Contact::factory()->create(['workspace_id' => $other['workspace']->id]);
        $theirs = Conversation::create([
            'workspace_id' => $other['workspace']->id,
            'contact_id' => $theirContact->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        Message::create([
            'conversation_id' => $theirs->id,
            'direction' => 'out',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'x',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($this->ctx['user'])->get(route('client.inbox.index', ['channel' => 'email']));
        $ids = array_column($response->viewData('page')['props']['conversations']['data'], 'id');

        $this->assertNotContains($theirs->id, $ids);
    }
}
