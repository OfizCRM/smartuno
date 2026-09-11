<?php

namespace Tests\Feature\Mail;

use App\Models\SmtpConfiguration;
use App\Services\Mail\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Tests\TestCase;

/**
 * How a configuration sends, as opposed to where it points.
 *
 * SMTP was the only way until a host that blocks outbound port 587 made it no
 * way at all. The block is the bad kind: it does not refuse the connection, it
 * swallows it, so a send hangs for a minute and then times out — which reads
 * like a wrong password and sends you looking in the wrong place. Port 443 is
 * never blocked, so the same Brevo account is reachable over its HTTP API.
 *
 * These pin that the choice is honoured, that an SMTP configuration is built
 * exactly as it was before, and that the two do not leak into each other.
 */
class MailTransportTest extends TestCase
{
    use RefreshDatabase;

    private function config(array $overrides = []): SmtpConfiguration
    {
        return SmtpConfiguration::create(array_merge([
            'transport' => SmtpConfiguration::TRANSPORT_SMTP,
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
            'username' => '9abcde@smtp-brevo.com',
            'password' => 'secretul',
            'encryption' => 'tls',
            'from_email' => 'contact@smartuno.ro',
            'from_name' => 'SmartUno',
            'is_active' => true,
        ], $overrides));
    }

    private function configure(SmtpConfiguration $c): array
    {
        app(MailService::class)->configureMailer($c);

        return (array) config('mail.mailers.dynamic_smtp');
    }

    // ─── SMTP, unchanged ─────────────────────────────────────────────────

    public function test_an_smtp_configuration_still_builds_an_smtp_mailer(): void
    {
        $built = $this->configure($this->config());

        $this->assertSame('smtp', $built['transport']);
        $this->assertSame('smtp-relay.brevo.com', $built['host']);
        $this->assertSame(587, $built['port']);
        $this->assertSame('tls', $built['encryption']);
        $this->assertSame('secretul', $built['password']);
    }

    public function test_a_row_written_without_a_transport_gets_smtp(): void
    {
        // The shape of every row that existed before the column did: the insert
        // names no transport and the database default fills it in. They must
        // keep working without anybody editing them.
        $id = DB::table('smtp_configurations')->insertGetId([
            'host' => 'mail.vechi.ro',
            'port' => 587,
            'username' => 'user',
            'password' => 'x',
            'encryption' => 'tls',
            'from_email' => 'vechi@smartuno.ro',
            'from_name' => 'Vechi',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacy = SmtpConfiguration::findOrFail($id);

        $this->assertSame('smtp', $legacy->transport);
        $this->assertFalse($legacy->usesApi());
        $this->assertSame('smtp', $this->configure($legacy)['transport']);
    }

    public function test_an_api_configuration_can_be_saved_without_smtp_fields(): void
    {
        // host/port/username/encryption were NOT NULL, so an API row could not
        // be inserted at all — the form accepted it and the insert failed on
        // `host`. Caught by this suite before it reached the admin screen.
        $c = SmtpConfiguration::create([
            'transport' => SmtpConfiguration::TRANSPORT_BREVO_API,
            'password' => 'xkeysib-cheia',
            'from_email' => 'contact@smartuno.ro',
            'from_name' => 'SmartUno',
            'is_active' => true,
        ]);

        $this->assertNull($c->fresh()->host);
        $this->assertSame('brevo_api', $c->fresh()->transport);
    }

    public function test_encryption_none_still_becomes_null(): void
    {
        $this->assertNull($this->configure($this->config(['encryption' => 'none']))['encryption']);
    }

    // ─── the API path ────────────────────────────────────────────────────

    public function test_an_api_configuration_builds_the_brevo_transport(): void
    {
        $built = $this->configure($this->config([
            'transport' => SmtpConfiguration::TRANSPORT_BREVO_API,
            'password' => 'xkeysib-cheia-api',
        ]));

        $this->assertSame('brevo_api', $built['transport']);
        $this->assertSame('xkeysib-cheia-api', $built['key']);
    }

    public function test_the_api_mailer_carries_the_from_address(): void
    {
        $built = $this->configure($this->config([
            'transport' => SmtpConfiguration::TRANSPORT_BREVO_API,
        ]));

        // Without this every message would fall back to the global MAIL_FROM,
        // which on a fresh install is hello@example.com.
        $this->assertSame('contact@smartuno.ro', $built['from']['address']);
        $this->assertSame('SmartUno', $built['from']['name']);
    }

    public function test_the_api_mailer_carries_no_smtp_fields(): void
    {
        $built = $this->configure($this->config([
            'transport' => SmtpConfiguration::TRANSPORT_BREVO_API,
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
        ]));

        // host/port/encryption describe a connection this transport never makes.
        // Leaving them in would suggest to the next reader that they matter.
        foreach (['host', 'port', 'encryption', 'username', 'password'] as $key) {
            $this->assertArrayNotHasKey($key, $built, "[{$key}] has no meaning for an API transport.");
        }
    }

    public function test_the_registered_transport_actually_resolves(): void
    {
        $this->configure($this->config([
            'transport' => SmtpConfiguration::TRANSPORT_BREVO_API,
            'password' => 'xkeysib-cheia-api',
        ]));

        // The whole point. Laravel ships transports for SES, Postmark and
        // Resend and none for Brevo, so if AppServiceProvider's Mail::extend()
        // ever stops running, this throws InvalidArgumentException — and it
        // would throw in production, on the first password reset, not here.
        $this->assertInstanceOf(
            BrevoApiTransport::class,
            Mail::mailer('dynamic_smtp')->getSymfonyTransport(),
        );
    }

    // ─── the summary line ────────────────────────────────────────────────

    public function test_the_summary_says_something_useful_for_each_kind(): void
    {
        $this->assertSame('smtp-relay.brevo.com:587', $this->config()->summary);

        // "host:port" on a row with neither renders as a bare colon, which
        // looks like a broken row rather than a different kind of row.
        $this->assertSame(
            'Brevo API (HTTPS)',
            $this->config(['transport' => SmtpConfiguration::TRANSPORT_BREVO_API, 'host' => null, 'port' => null])->summary,
        );
    }
}
