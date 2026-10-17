<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

/**
 * An email ready to send: HTML body, from the shop's sender.
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
    ) {}
}
