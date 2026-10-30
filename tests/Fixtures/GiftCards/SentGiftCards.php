<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\GiftCards;

use OrillaCart\GiftCards\Application\GiftCardMail;

final class SentGiftCards implements GiftCardMail
{
    /** @var list<array{to: string, code: string, amount: string}> */
    public array $sent = [];

    /** As when the gift card email is turned off, or the mail fails. */
    public bool $refuse = false;

    /** @var list<?string> the language each card was sent in */
    public array $locales = [];

    /** @var list<?string> the last day of each card sent */
    public array $expiries = [];

    public function send(string $to, string $code, string $amount, string $currency, ?string $expiresOn, string $recipientName, string $message, ?string $locale = null): bool
    {
        if ($this->refuse) {
            return false;
        }
        $this->sent[] = ['to' => $to, 'code' => $code, 'amount' => $amount];
        $this->expiries[] = $expiresOn;
        $this->locales[] = $locale;

        return true;
    }
}
