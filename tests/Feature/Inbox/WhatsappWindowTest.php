<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The WhatsApp 24-hour service window.
 *
 * Meta only accepts a free-form message within 24 hours of the customer's last
 * inbound one; after that only an approved template gets through. This test
 * exists because the check had been inverted since the codebase was bought:
 * Carbon 3 returns a SIGNED difference, so now()->diffInHours($past) is
 * negative and "-47.9 < 24" is true for ever. The window never closed, we sent
 * anyway, and Meta refused it — surfacing to the operator as a failed bubble
 * with a raw provider error rather than as "use a template".
 */
class WhatsappWindowTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(string $channel = 'whatsapp'): Conversation
    {
        $ws = $this->createWorkspaceContext()['workspace'];

        $account = ChannelAccount::create([
            'workspace_id' => $ws->id,
            'channel' => $channel,
            'status' => 'active',
            'display_name' => 'Acct',
            'phone_number_id' => '123456',
            'credentials' => [],
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $ws->id]);

        return Conversation::create([
            'workspace_id' => $ws->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function inboundAt(Conversation $conversation, Carbon $at): void
    {
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'salut',
            'status' => 'delivered',
            'sent_at' => $at,
        ]);
    }

    public function test_the_window_is_open_just_inside_twenty_four_hours(): void
    {
        $conversation = $this->conversation();
        $this->inboundAt($conversation, now()->subHours(23)->subMinutes(59));

        $this->assertTrue($conversation->fresh()->isWhatsappWindowOpen());
    }

    public function test_the_window_closes_after_twenty_four_hours(): void
    {
        $conversation = $this->conversation();
        $this->inboundAt($conversation, now()->subHours(30));

        // The whole point. Before the fix this returned true at any age.
        $this->assertFalse($conversation->fresh()->isWhatsappWindowOpen());
    }

    public function test_a_week_old_conversation_is_not_open(): void
    {
        $conversation = $this->conversation();
        $this->inboundAt($conversation, now()->subDays(7));

        $this->assertFalse($conversation->fresh()->isWhatsappWindowOpen());
    }

    public function test_a_conversation_with_no_inbound_message_is_closed(): void
    {
        $conversation = $this->conversation();

        // Sending a template does not open the window; only the customer does.
        $this->assertFalse($conversation->fresh()->isWhatsappWindowOpen());
    }

    public function test_a_newer_inbound_message_reopens_it(): void
    {
        $conversation = $this->conversation();
        $this->inboundAt($conversation, now()->subDays(3));
        $this->inboundAt($conversation, now()->subMinutes(5));

        $this->assertTrue($conversation->fresh()->isWhatsappWindowOpen());
    }

    public function test_other_channels_are_not_gated_at_all(): void
    {
        foreach (['messenger', 'instagram', 'email'] as $channel) {
            $conversation = $this->conversation($channel);
            $this->assertTrue(
                $conversation->fresh()->isWhatsappWindowOpen(),
                $channel.' must not be held to WhatsApp session rules',
            );
        }
    }
}
