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
     * @param array<string, string> $fields   the answers to checkout's questions so far (add-ons' fields and the
     *                                        shop's forms), by field id, as the customer gave them: a checked box
     *                                        is "1", one left unchecked is absent; an edited order's are its own
     *                                        (API 21)
     */
    public function __construct(
        public array $lines,
        public string $currency,
        public ?int $customerId = null,
        public ?string $email = null,
        public ?int $orderNumber = null,
        public array $fields = [],
    ) {}
}
