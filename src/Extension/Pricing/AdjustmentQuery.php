<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Pricing;

/**
 * The order being priced, as an OrderAdjuster sees it: its lines after the coupons, before tax.
 */
final readonly class AdjustmentQuery
{
    /**
     * @param list<AdjustedLine> $lines
     * @param int|null           $customerId  the WordPress user; null for a guest
     * @param int|null           $orderNumber the order being priced again, when the shop edits one
     */
    public function __construct(
        public array $lines,
        public string $currency,
        public ?int $customerId = null,
        public ?string $email = null,
        public ?int $orderNumber = null,
    ) {}
}
