<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

/** Money given back as something to spend in the shop: how, for the order's history, and the email still to send. */
final readonly class GiftCardCredit
{
    /**
     * @param \Closure(): bool|null $mail sends a guest's new card, once the refund is saved; null for store credit
     */
    public function __construct(
        public string $how,
        private ?\Closure $mail = null,
    ) {}

    /** @return bool false when the email could not go */
    public function send(): bool
    {
        return $this->mail === null || ($this->mail)();
    }
}
