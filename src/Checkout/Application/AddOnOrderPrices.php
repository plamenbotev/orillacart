<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\AdjustOrderPrice;
use OrillaCart\Extension\Order\OrderPrices;
use OrillaCart\Ordering\Domain\PriceAdjustment;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * Orders' prices as the extension API lets add-ons change them: on hold only, priced again, kept with the
 * add-on's id.
 */
final readonly class AddOnOrderPrices implements OrderPrices
{
    public function __construct(private OrderEditing $editing) {}

    public function adjust(int $number, string $source, string $label, string $amount, ?int $line = null, string $reason = ''): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $source) !== 1 || preg_match('/^-?\d{1,11}(\.\d{1,4})?$/', $amount) !== 1 || trim($label) === '') {
            throw new \InvalidArgumentException('A change to an order\'s price needs the add-on\'s id, a label and an amount such as -5.00.');
        }
        try {
            $this->editing->adjust($number, new AdjustOrderPrice($amount, $label, $line, $reason), null, PriceAdjustment::ADD_ON, $source);
        } catch (RuleViolation $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
