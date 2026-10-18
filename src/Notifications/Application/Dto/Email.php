<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

/**
 * An email ready to send: HTML body, from the shop's sender. An order email names its kind (order_received,
 * new_order…) and order, for the add-ons that attach files or add headers to it.
 */
final readonly class Email
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $html,
        public string $fromName,
        public string $fromAddress,
        public ?string $replyTo = null,
        public ?string $kind = null,
        public ?int $orderNumber = null,
    ) {}
}
