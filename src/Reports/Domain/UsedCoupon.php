<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * A coupon an order used, and what it took off, as the reports count it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_order_coupons')]
#[ORM\Index(name: 'report_coupon_day', columns: ['counted', 'currency', 'day'])]
#[ORM\Index(name: 'report_coupon_order', columns: ['order_id'])]
class UsedCoupon
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column]
        public readonly int $orderId,
        #[ORM\Column(type: 'date_immutable')]
        public readonly \DateTimeImmutable $day,
        #[ORM\Column]
        public readonly bool $counted,
        #[ORM\Column(length: 3)]
        public readonly string $currency,
        #[ORM\Column(length: 40)]
        public readonly string $code,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $amount,
    ) {}
}
