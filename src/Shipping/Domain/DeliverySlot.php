<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * A time of day a carrier delivers in, such as 9:00 to 12:00, and how many orders it takes each day.
 */
final readonly class DeliverySlot
{
    /**
     * @param string   $id    stays the same while the shop edits it: orders count against it by it
     * @param string   $from  "09:00"
     * @param string   $to    "12:00", after $from
     * @param int|null $limit orders a day; null for no limit
     *
     * @throws ShippingRuleViolation
     */
    public function __construct(
        public string $id,
        public string $from,
        public string $to,
        public ?int $limit = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1) {
            throw new ShippingRuleViolation(\sprintf('"%s" is not an id for a time slot: lowercase letters, digits and dashes, up to 40.', $id), 'orillacart_invalid_delivery_slot');
        }
        if (!self::isTime($from) || !self::isTime($to) || $to <= $from) {
            throw new ShippingRuleViolation(\sprintf('A time slot runs from one time of day to a later one, such as 09:00 to 12:00; not %s to %s.', $from, $to), 'orillacart_invalid_delivery_slot');
        }
        if ($limit !== null && $limit < 1) {
            throw new ShippingRuleViolation('A time slot takes at least one order a day, or has no limit.', 'orillacart_invalid_delivery_slot');
        }
    }

    /** "09:00–12:00". */
    public function label(): string
    {
        return "{$this->from}–{$this->to}";
    }

    public static function isTime(string $time): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }
}
