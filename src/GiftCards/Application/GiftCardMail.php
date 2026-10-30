<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application;

/**
 * Sends a gift card's code to whom it is for.
 */
interface GiftCardMail
{
    /**
     * @param string|null $expiresOn "2027-10-21"
     * @param string|null $locale    the reader's language, as the order that bought or refunded it was placed in;
     *                               null for the site's
     *
     * @return bool whether it went out; false when the shop turned the email off or the mail system refused it
     */
    public function send(string $to, string $code, string $amount, string $currency, ?string $expiresOn, string $recipientName, string $message, ?string $locale = null): bool;
}
