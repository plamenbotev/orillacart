<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * An order as the reports count it: one row per order, written again whenever the order changes, so reports
 * never read the orders themselves. Only paid orders count as sales; the others are kept to count them too.
 * Its day is the shop's calendar day it was placed on.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_orders')]
#[ORM\Index(name: 'report_order_day', columns: ['counted', 'currency', 'day'])]
#[ORM\Index(name: 'report_order_customer', columns: ['customer_key', 'placed_at'])]
#[ORM\Index(name: 'report_order_region', columns: ['country', 'region', 'day'])]
class SoldOrder
{
    /**
     * @param string $customerKey who bought: "c42" for customer 42, "e" and a keyed hash of a guest's email, or
     *                            "o" and the order number for an erased customer
     * @param string $gross       the items' prices before discounts and tax
     * @param string $shippingTotal shipping before tax
     * @param string $tax         items' and shipping tax
     * @param string $total       what the customer paid, before refunds
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public readonly int $orderId,
        #[ORM\Column(type: 'date_immutable')]
        public readonly \DateTimeImmutable $day,
        #[ORM\Column]
        public readonly \DateTimeImmutable $placedAt,
        #[ORM\Column]
        public readonly bool $counted,
        #[ORM\Column(length: 20)]
        public readonly string $status,
        #[ORM\Column(length: 3)]
        public readonly string $currency,
        #[ORM\Column(length: 210)]
        public readonly string $customerKey,
        #[ORM\Column(nullable: true)]
        public readonly ?int $customerId,
        #[ORM\Column(length: 200)]
        public readonly string $customerName,
        #[ORM\Column(length: 2)]
        public readonly string $country,
        #[ORM\Column(length: 100)]
        public readonly string $payment,
        #[ORM\Column(length: 255)]
        public readonly string $shipping,
        #[ORM\Column]
        public readonly int $items,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $gross,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $discount,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $shippingTotal,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $tax,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $total,
        /** The state it went to, "TX", where the country has them; the economic nexus report counts by it. */
        #[ORM\Column(length: 3, nullable: true)]
        public readonly ?string $region = null,
        /** Sold to a business that accounts for the VAT itself (EU reverse charge): not a sale to a consumer. */
        #[ORM\Column(options: ['default' => false])]
        public readonly bool $reverseCharge = false,
        /** Units of its currency for one of the shop's, as it was priced at; null in the shop's own currency. */
        #[ORM\Column(type: 'decimal', precision: 18, scale: 8, nullable: true)]
        public readonly ?string $exchangeRate = null,
    ) {}
}
