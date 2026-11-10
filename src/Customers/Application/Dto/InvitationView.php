<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

/**
 * An invitation to make an account at the shop, as the shop's staff see it: whoever has the email elsewhere on the
 * network, it reads the same.
 */
final readonly class InvitationView
{
    /**
     * @param list<int> $groupIds the customer groups the account joins once made
     * @param string    $sentAt   ISO 8601, when the last link went
     * @param string    $expiresAt ISO 8601, when it stops working
     * @param bool      $mayResend whether a new link may go now: the last stopped working, or went a day ago
     */
    public function __construct(
        public int $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        public array $groupIds,
        public ?int $invitedBy,
        public string $sentAt,
        public string $expiresAt,
        public bool $expired,
        public bool $mayResend,
    ) {}
}
