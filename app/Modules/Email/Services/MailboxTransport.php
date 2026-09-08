<?php

namespace App\Modules\Email\Services;

use App\Modules\Ecommerce\Services\StoreUrlGuard;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Builds an SMTP transport for one mailbox.
 *
 * Shared by the settings test and the outbound driver so there is one place that
 * decides what "SSL on 465" means, and one place that re-checks the host before
 * a socket is opened.
 */
class MailboxTransport
{
    /** @param array<string, mixed> $smtp */
    public function make(array $smtp): EsmtpTransport
    {
        $host = (string) ($smtp['host'] ?? '');

        $blocked = StoreUrlGuard::guardHost($host);
        if ($blocked !== null) {
            throw new \RuntimeException($blocked);
        }

        // Implicit TLS on 465; STARTTLS is negotiated by the transport itself on
        // a plain port, so only the "ssl" case is passed as TLS here.
        $transport = new EsmtpTransport($host, (int) $smtp['port'], ($smtp['encryption'] ?? 'ssl') === 'ssl');

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(15);
        }

        $transport->setUsername((string) $smtp['username']);
        $transport->setPassword((string) $smtp['password']);

        return $transport;
    }
}
