<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * One rate charged on one line of an order, as returns are filed: who it is owed to, what it was charged on,
 * how much, and what refunds gave back.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_order_taxes')]
#[ORM\Index(name: 'report_tax_day', columns: ['counted', 'currency', 'day'])]
#[ORM\Index(name: 'report_tax_order', columns: ['order_id'])]
class CollectedTax
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
        #[ORM\Column(length: 2, options: ['fixed' => true])]
        public readonly string $country,
        #[ORM\Column(length: 3, nullable: true)]
        public readonly ?string $region,
        #[ORM\Column(length: 10)]
        public readonly string $level,
        #[ORM\Column(length: 60)]
        public readonly string $code,
        #[ORM\Column(length: 100)]
        public readonly string $jurisdiction,
        #[ORM\Column(type: 'decimal', precision: 7, scale: 4)]
        public readonly string $percent,
        /** "item", "shipping" or "fee": what it was charged on. */
        #[ORM\Column(length: 10)]
        public readonly string $kind,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $taxable,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $amount,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $refunded,
        /** Sold without tax under the customer's exemption: an exempt sale of the jurisdiction. */
        #[ORM\Column]
        public readonly bool $exempt = false,
    ) {}
}
