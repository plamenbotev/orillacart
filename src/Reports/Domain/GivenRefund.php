<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * Money given back, on the shop's calendar day it went: refunds lower the sales of the day they were made, not of
 * the day the order was placed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_refunds')]
#[ORM\Index(name: 'report_refund_day', columns: ['currency', 'day'])]
#[ORM\Index(name: 'report_refund_order', columns: ['order_id'])]
class GivenRefund
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public readonly int $refundId,
        #[ORM\Column]
        public readonly int $orderId,
        #[ORM\Column(type: 'date_immutable')]
        public readonly \DateTimeImmutable $day,
        #[ORM\Column(length: 3)]
        public readonly string $currency,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $amount,
    ) {}
}
