<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\GiftCards;

use OrillaCart\GiftCards\Application\GiftCardMail;

final class SentGiftCards implements GiftCardMail
{
    /** @var list<array{to: string, code: string, amount: string}> */
    public array $sent = [];

    public function send(string $to, string $code, string $amount, string $currency, ?string $expiresOn, string $recipientName, string $message): bool
    {
        $this->sent[] = ['to' => $to, 'code' => $code, 'amount' => $amount];

        return true;
    }
}
