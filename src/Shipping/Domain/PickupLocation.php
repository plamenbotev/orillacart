<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * One of the shop's own places where customers collect their orders: its address, when it is open, and what
 * collecting there costs (nothing, usually).
 */
final readonly class PickupLocation
{
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_TEXT_LENGTH = 255;

    public string $name;
    public string $address;
    public string $hours;

    /**
     * @param string $id    stays the same while the shop edits it: orders name the place by it
     * @param string $hours when it is open, as the shop writes it: "Mon–Fri 9–18, Sat 10–14"
     * @param Money  $fee   before tax, or including it when the shop's prices do
     *
     * @throws ShippingRuleViolation
     */
    public function __construct(
        public string $id,
        string $name,
        string $address,
        string $hours,
        public Money $fee,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1) {
            throw new ShippingRuleViolation(\sprintf('"%s" is not an id for a pickup place: lowercase letters, digits and dashes, up to 40.', $id), 'orillacart_invalid_pickup_location');
        }
        $this->name = trim($name);
        $this->address = trim($address);
        $this->hours = trim($hours);
        if ($this->name === '' || mb_strlen($this->name) > self::MAX_NAME_LENGTH) {
            throw new ShippingRuleViolation(\sprintf('A pickup place needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_pickup_location');
        }
        if ($this->address === '' || mb_strlen($this->address) > self::MAX_TEXT_LENGTH || mb_strlen($this->hours) > self::MAX_TEXT_LENGTH) {
            throw new ShippingRuleViolation(\sprintf('A pickup place needs an address, and its address and hours have up to %d characters.', self::MAX_TEXT_LENGTH), 'orillacart_invalid_pickup_location');
        }
        if ($fee->isNegative()) {
            throw new ShippingRuleViolation('Collecting an order cannot cost less than nothing.', 'orillacart_invalid_pickup_location');
        }
    }
}
