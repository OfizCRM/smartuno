<?php

namespace Tests\Feature\Email;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Configuring the firm's mailbox.
 *
 * The two things worth pinning: the credentials never leave the server in
 * readable form, and the host fields cannot be pointed at the machine the
 * application itself runs on.
 */
class MailboxSettingsTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'email' => 'office@firma.ro',
            'from_name' => 'Farmacia Verde',
            'imap' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'office@firma.ro', 'password' => 'secret-imap'],
            'smtp' => ['host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'office@firma.ro', 'password' => 'secret-smtp'],
            'poll_minutes' => 10,
            'initial_days' => 2,
        ], $overrides);
    }

    private function mailbox(): ?ChannelAccount
    {
        return ChannelAccount::where('workspace_id', $this->ctx['workspace']->id)->where('channel', 'email')->first();
    }

    public function test_saving_a_mailbox_stores_it_as_an_email_channel_account(): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.mailbox.store'), $this->payload())
            ->assertRedirect();

        $account = $this->mailbox();

        $this->assertNotNull($account);
        $this->assertSame('email', $account->channel);
        $this->assertSame('office@firma.ro', $account->display_name);
        $this->assertSame('secret-imap', $account->credentials['imap']['password']);
        $this->assertSame(10, $account->meta_json['poll_minutes']);
    }

    public function test_the_passwords_are_encrypted_at_rest_and_never_reach_the_page(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.mailbox.store'), $this->payload());

        // Straight from the column, bypassing the cast.
        $raw = (string) DB::table('channel_accounts')->where('id', $this->mailbox()->id)->value('credentials');

        $this->assertStringNotContainsString('secret-imap', $raw);
        $this->assertStringNotContainsString('secret-smtp', $raw);

        $props = $this->actingAs($this->ctx['user'])->get(route('client.mailbox.index'))
            ->viewData('page')['props'];

        $this->assertStringNotContainsString('secret-imap', json_encode($props));
        $this->assertTrue($props['mailbox']['imap']['has_password']);
        $this->assertArrayNotHasKey('password', $props['mailbox']['imap']);
    }

    public function test_saving_without_a_password_keeps_the_stored_one(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.mailbox.store'), $this->payload());

        $this->actingAs($this->ctx['user'])
            ->post(route('client.mailbox.store'), $this->payload([
                'from_name' => 'Alt nume',
                'imap' => ['password' => ''],
                'smtp' => ['password' => ''],
            ]))
            ->assertRedirect();

        $account = $this->mailbox();

        $this->assertSame('Alt nume', $account->credentials['from_name']);
        $this->assertSame('secret-imap', $account->credentials['imap']['password']);
    }

    public function test_a_first_save_requires_a_password(): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.mailbox.store'), $this->payload(['imap' => ['password' => '']]))
            ->assertSessionHasErrors('imap.password');

        $this->assertNull($this->mailbox());
    }

    #[DataProvider('internalHosts')]
    public function test_an_internal_host_is_refused(string $host): void
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.mailbox.store'), $this->payload(['imap' => ['host' => $host]]))
            ->assertSessionHasErrors('imap.host');

        $this->assertNull($this->mailbox());
    }

    /** @return array<string, array{string}> */
    public static function internalHosts(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'localhost' => ['localhost'],
            'private range' => ['10.0.0.5'],
            // The address a cloud instance answers its own credentials on.
            'cloud metadata' => ['169.254.169.254'],
        ];
    }

    public function test_one_workspace_cannot_see_or_replace_another_mailbox(): void
    {
        $other = $this->createWorkspaceContext();
        $this->actingAs($other['user'])->post(route('client.mailbox.store'), $this->payload([
            'email' => 'altcineva@altfirma.ro',
        ]));

        $props = $this->actingAs($this->ctx['user'])->get(route('client.mailbox.index'))
            ->viewData('page')['props'];

        $this->assertNull($props['mailbox']);

        $this->actingAs($this->ctx['user'])->post(route('client.mailbox.store'), $this->payload());

        $this->assertSame(2, ChannelAccount::where('channel', 'email')->count());
        $this->assertSame(
            'altcineva@altfirma.ro',
            ChannelAccount::where('workspace_id', $other['workspace']->id)->where('channel', 'email')->value('display_name')
        );
    }

    public function test_removing_the_mailbox_keeps_its_conversations_findable(): void
    {
        $this->actingAs($this->ctx['user'])->post(route('client.mailbox.store'), $this->payload());
        $account = $this->mailbox();

        $conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel_account_id' => $account->id,
            'contact_id' => Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id])->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Bună ziua',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->ctx['user'])->delete(route('client.mailbox.destroy'))->assertRedirect();

        $conversation->refresh();

        // Detached, not deleted — resolvedChannel() then reads the channel off
        // the messages, so the thread stays an email thread and stays findable.
        $this->assertNull($conversation->channel_account_id);
        $this->assertSame('email', $conversation->resolvedChannel());
    }
}
