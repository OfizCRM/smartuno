<?php

namespace App\Modules\Offers\Exceptions;

/**
 * A draft that did not happen, with the one thing the record of it needs: a
 * short key saying why.
 *
 * offer_draft_attempts.reason is string(64) and holds a TRANSLATION KEY, never a
 * provider's HTTP body — that body is where an API key, another tenant's id or a
 * whole prompt can end up, and the row is read on a screen. The raw error is
 * kept as the previous exception so report() still logs it in full.
 *
 * The message carried alongside is already translated and already safe to show:
 * the same contract CatalogDescriber established in stage 3, so a caller can
 * either translate the key on the client or print the message on the server
 * without having to know which of the two is safe.
 */
class OfferDraftFailed extends \RuntimeException
{
    public function __construct(
        /** A translation key, short enough for the column, free of anything secret. */
        public readonly string $reasonKey,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
