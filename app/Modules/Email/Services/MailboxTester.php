<?php

namespace App\Modules\Email\Services;

use App\Modules\Ecommerce\Services\StoreUrlGuard;
use Webklex\PHPIMAP\ClientManager;

/**
 * Proves a mailbox actually works, before the tenant leaves the page.
 *
 * Both halves connect and authenticate and then stop. The SMTP half
 * deliberately does not send a test message: a mailbox is checked far more
 * often than it changes, and nobody wants a pile of "test" mails in their
 * Sent folder — or delivered to a real address by a typo.
 */
class MailboxTester
{
    public function __construct(private readonly MailboxTransport $transports) {}

    /**
     * @param  array{host: string, port: int, encryption: string, username: string, password: string}  $imap
     * @param  array{host: string, port: int, encryption: string, username: string, password: string}  $smtp
     * @return array{imap: array{ok: bool, message: string}, smtp: array{ok: bool, message: string}}
     */
    public function test(array $imap, array $smtp): array
    {
        return [
            'imap' => $this->tryOne(fn () => $this->probeImap($imap), $imap['host']),
            'smtp' => $this->tryOne(fn () => $this->probeSmtp($smtp), $smtp['host']),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function tryOne(callable $probe, string $host): array
    {
        // The host is re-checked here and not only at validation time: this
        // method is what opens the socket, and a guard that lives anywhere else
        // can be bypassed by a future caller.
        $blocked = StoreUrlGuard::guardHost($host);
        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked];
        }

        try {
            $probe();

            return ['ok' => true, 'message' => __('Connected.')];
        } catch (\Throwable $e) {
            // Never echo the raw exception: a failed SMTP AUTH can quote the
            // credentials back, and this string is rendered in the browser.
            return ['ok' => false, 'message' => $this->humanise($e)];
        }
    }

    /** @param array<string, mixed> $imap */
    private function probeImap(array $imap): void
    {
        $client = (new ClientManager)->make([
            'host' => $imap['host'],
            'port' => (int) $imap['port'],
            'protocol' => 'imap',
            'encryption' => $imap['encryption'] === 'none' ? false : $imap['encryption'],
            'validate_cert' => true,
            'username' => $imap['username'],
            'password' => $imap['password'],
            'authentication' => null,
        ]);

        // The library writes a PHP warning straight to output when the socket
        // cannot be opened, which would land in the JSON response.
        $previous = set_error_handler(static fn () => true);

        try {
            $client->connect();
            $client->getFolders(false);
        } finally {
            restore_error_handler();
            if ($previous) {
                set_error_handler($previous);
            }
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // Already closed; nothing to salvage.
            }
        }
    }

    /** @param array<string, mixed> $smtp */
    private function probeSmtp(array $smtp): void
    {
        $transport = $this->transports->make($smtp);

        // start() opens the connection, negotiates STARTTLS when the port asks
        // for it, and authenticates. It sends no mail.
        $transport->start();
        $transport->stop();
    }

    private function humanise(\Throwable $e): string
    {
        $raw = strtolower($e->getMessage());

        return match (true) {
            str_contains($raw, 'authenticat') || str_contains($raw, 'login') || str_contains($raw, 'credential') => __('The server refused the username or password.'),
            str_contains($raw, 'certificate') || str_contains($raw, 'ssl') || str_contains($raw, 'tls') => __('The secure connection failed. Check the port and the encryption setting.'),
            str_contains($raw, 'timed out') || str_contains($raw, 'timeout') => __('The server did not answer in time. Check the host and the port.'),
            default => __('Could not connect. Check the host, the port and the encryption setting.'),
        };
    }
}
