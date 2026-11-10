<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application;

use OrillaCart\Shared\Security\Signatures;
use Psr\Clock\ClockInterface;

/**
 * The tokens of the links from a paid order to the review form of each product in it: the order number, the
 * product and the day the link was made, signed for reviews alone. A link says who bought the product, so their
 * review is a buyer's; it opens nothing else of the order, as the receipt's token would. It works for VALID_DAYS.
 */
final readonly class ReviewTokens
{
    public const int VALID_DAYS = 180;
    public const string PATTERN = '[0-9]{1,10}-[0-9]{1,20}-[0-9]{1,6}-[a-f0-9]{32}';
    private const string PURPOSE = 'review';

    public function __construct(
        private Signatures $signatures,
        private ClockInterface $clock,
    ) {}

    public function issue(int $orderNumber, int $productId): string
    {
        $day = $this->today();

        return "{$orderNumber}-{$productId}-{$day}-" . $this->signatures->sign(self::PURPOSE, "{$orderNumber}-{$productId}-{$day}");
    }

    /**
     * The order and product a token is for, when the shop made it; whether it expired is the caller's to tell.
     *
     * @return array{order: int, product: int, expired: bool}|null null for a token the shop never made
     */
    public function read(#[\SensitiveParameter] string $token): ?array
    {
        if (preg_match('/^(\d{1,10})-(\d{1,20})-(\d{1,6})-([a-f0-9]{32})$/', $token, $parts) !== 1 || !$this->signatures->verify(self::PURPOSE, "{$parts[1]}-{$parts[2]}-{$parts[3]}", $parts[4])) {
            return null;
        }
        $age = $this->today() - (int) $parts[3];

        return ['order' => (int) $parts[1], 'product' => (int) $parts[2], 'expired' => $age < 0 || $age >= self::VALID_DAYS];
    }

    private function today(): int
    {
        return intdiv($this->clock->now()->getTimestamp(), 86400);
    }
}
