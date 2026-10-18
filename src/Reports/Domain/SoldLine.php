<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * An order line as the reports count it, with its order's day and whether the order counts, so product reports
 * read this table alone.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_order_lines')]
#[ORM\Index(name: 'report_line_day', columns: ['counted', 'currency', 'day', 'product_id'])]
#[ORM\Index(name: 'report_line_order', columns: ['order_id'])]
class SoldLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * @param string $net what the line brought: its total less its discount, before tax
     */
    public function __construct(
        #[ORM\Column]
        public readonly int $orderId,
        #[ORM\Column(type: 'date_immutable')]
        public readonly \DateTimeImmutable $day,
        #[ORM\Column]
        public readonly bool $counted,
        #[ORM\Column(length: 3)]
        public readonly string $currency,
        #[ORM\Column]
        public readonly int $productId,
        #[ORM\Column(nullable: true)]
        public readonly ?int $variationId,
        #[ORM\Column(length: 255)]
        public readonly string $name,
        #[ORM\Column]
        public readonly int $quantity,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        public readonly string $net,
    ) {}
}
