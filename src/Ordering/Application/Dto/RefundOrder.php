<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderNote;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: money given back for an order. The amount is the shop's to choose: the items' prices are a
 * suggestion, so it can keep a restocking fee, or refund an amount alone as a goodwill gesture.
 */
final readonly class RefundOrder
{
    /**
     * @param list<RefundLine> $lines the items it covers, if any
     */
    public function __construct(
        #[Assert\Regex('/^\d{1,11}(\.\d{1,4})?$/', message: 'Use an amount with up to four decimals, such as 19.90.')]
        public string $amount,
        #[Assert\Valid]
        #[Assert\Count(max: 500)]
        public array $lines = [],
        public bool $shipping = false,
        public bool $restock = false,
        #[Assert\Length(max: OrderNote::MAX_LENGTH)]
        public string $reason = '',
    ) {}
}
