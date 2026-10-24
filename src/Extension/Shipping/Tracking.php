<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * Where a shipment is, as the courier says.
 */
final readonly class Tracking
{
    /** Booked with the courier, not collected yet: the order waits, and the shop may still cancel it (version 13). */
    public const string BOOKED = 'booked';
    public const string ON_THE_WAY = 'on_the_way';
    public const string DELIVERED = 'delivered';
    /** The courier is bringing it back to the shop. */
    public const string RETURNED = 'returned';

    /**
     * @param string $description the courier's own words: "At the Sofia depot"
     */
    public function __construct(
        public string $status,
        public string $description = '',
    ) {
        if (!\in_array($status, [self::BOOKED, self::ON_THE_WAY, self::DELIVERED, self::RETURNED], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a tracking status.', $status));
        }
    }
}
