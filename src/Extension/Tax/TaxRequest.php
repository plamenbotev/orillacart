<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Tax;

/**
 * What a tax service is asked to rate: where the goods go from and to, who buys, and every line.
 */
final readonly class TaxRequest
{
    /**
     * @param list<TaxRequestLine> $lines
     * @param string               $date        the sale's day, Y-m-d
     * @param int|null             $customerId  the WordPress user; null for a guest
     * @param string|null          $exemption   the customer's exemption certificate's number, when the shop has one
     *                                          on file for them; the service decides where it holds
     * @param string|null          $exemptionReason why: "resale", "nonprofit", "government" or "other"
     * @param int|null             $orderNumber the order being priced again, when the shop edits one
     */
    public function __construct(
        public string $currency,
        public string $date,
        public TaxAddress $shipFrom,
        public TaxAddress $shipTo,
        public array $lines,
        public ?int $customerId = null,
        public ?string $exemption = null,
        public ?string $exemptionReason = null,
        public ?int $orderNumber = null,
    ) {}
}
