<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * What a US state counts before a seller from elsewhere owes it sales tax ("economic nexus"): sales over an
 * amount, or a number of orders, in a year. Either one crossed is enough; a null one does not count.
 */
final readonly class NexusThreshold
{
    /**
     * @param string|null $sales  decimal, in the shop's currency: "100000"
     * @param int|null    $orders how many orders
     */
    public function __construct(
        public ?string $sales,
        public ?int $orders,
    ) {
        if ($sales !== null && (preg_match('/^\d{1,11}(\.\d{1,2})?$/', $sales) !== 1 || Money::of($sales)->isZero())) {
            throw new RuleViolation('A sales threshold is an amount above zero, such as 100000.', 'orillacart_invalid_nexus_threshold');
        }
        if ($orders !== null && $orders < 1) {
            throw new RuleViolation('An orders threshold is at least 1.', 'orillacart_invalid_nexus_threshold');
        }
    }

    /** Whether this year's sales or orders reach it. */
    public function crossedBy(Money $sales, int $orders): bool
    {
        return ($this->sales !== null && $sales->compare(Money::of($this->sales)) >= 0) || ($this->orders !== null && $orders >= $this->orders);
    }
}
