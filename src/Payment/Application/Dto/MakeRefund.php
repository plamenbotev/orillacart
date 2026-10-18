<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Application\Dto\RefundOrder;
use OrillaCart\Ordering\Domain\OrderNote;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: give money back for an order, through the gateway that took the payment when it can, or record a
 * refund the shop made outside, such as a bank transfer back.
 */
final readonly class MakeRefund
{
    /**
     * @param list<RefundLine> $lines   the items it covers, if any
     * @param bool             $outside the shop gave the money back itself; the gateway is not asked
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
        public bool $outside = false,
    ) {}

    public function forOrder(): RefundOrder
    {
        return new RefundOrder($this->amount, $this->lines, $this->shipping, $this->restock, $this->reason);
    }
}
