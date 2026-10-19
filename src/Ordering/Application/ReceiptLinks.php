<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Shared\Security\Signatures;
use Psr\Clock\ClockInterface;

/**
 * The tokens of receipt links: the order number and the day the link was made, signed. Each email and page makes
 * its own, so the order keeps no secret, and a link works for VALID_DAYS: after that, its receipt page sends a
 * new one to the order's email, where the first went.
 */
final readonly class ReceiptLinks
{
    public const int VALID_DAYS = 90;
    public const string PATTERN = '[0-9]{1,10}-[0-9]{1,6}-[a-f0-9]{32}';
    private const string PURPOSE = 'receipt';

    public function __construct(
        private Signatures $signatures,
        private ClockInterface $clock,
    ) {}

    public function issue(int $orderNumber): string
    {
        $day = intdiv($this->clock->now()->getTimestamp(), 86400);

        return "{$orderNumber}-{$day}-" . $this->signatures->sign(self::PURPOSE, "{$orderNumber}-{$day}");
    }

    /**
     * The order a token is for, when the shop made it; whether it expired is the caller's to tell.
     *
     * @return array{int, bool}|null the order number and whether the link expired; null for a token the shop never made
     */
    public function read(string $token): ?array
    {
        if (preg_match('/^(\d{1,10})-(\d{1,6})-([a-f0-9]{32})$/', $token, $parts) !== 1 || !$this->signatures->verify(self::PURPOSE, "{$parts[1]}-{$parts[2]}", $parts[3])) {
            return null;
        }
        $today = intdiv($this->clock->now()->getTimestamp(), 86400);

        return [(int) $parts[1], $today - (int) $parts[2] >= self::VALID_DAYS];
    }
}
