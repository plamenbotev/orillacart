<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * A change to a line's price after its unit price, as the order keeps it: what made it (a coupon's code, an
 * add-on, the shop manager), why, and when. An order-wide one is kept on each line as its share.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_adjustments')]
class OrderAdjustment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column(length: 100)]
    private string $source;

    #[ORM\Column(length: PriceAdjustment::MAX_LABEL_LENGTH)]
    private string $label;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $amount;

    #[ORM\Column(length: 10)]
    private string $scope;

    #[ORM\Column]
    private bool $freeShipping;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $reason;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: OrderLine::class, inversedBy: 'adjustments')]
        #[ORM\JoinColumn(name: 'line_id', nullable: false, onDelete: 'CASCADE')]
        private OrderLine $line,
        PriceAdjustment $adjustment,
    ) {
        $this->kind = $adjustment->kind;
        $this->source = mb_substr($adjustment->source, 0, 100);
        $this->label = mb_substr($adjustment->label, 0, PriceAdjustment::MAX_LABEL_LENGTH);
        $this->amount = $adjustment->amount->toFixed();
        $this->scope = $adjustment->scope;
        $this->freeShipping = $adjustment->freeShipping;
        $this->reason = $adjustment->reason === null ? null : mb_substr($adjustment->reason, 0, 200);
    }

    public function line(): OrderLine
    {
        return $this->line;
    }

    public function adjustment(): PriceAdjustment
    {
        return new PriceAdjustment($this->kind, $this->source, $this->label, Money::of($this->amount), $this->scope, $this->freeShipping, $this->reason);
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }
}
