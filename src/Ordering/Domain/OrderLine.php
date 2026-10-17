<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * One item of an order, kept as it was sold: name, options and prices do not follow later catalog changes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_lines')]
class OrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $productId;

    #[ORM\Column(nullable: true)]
    private ?int $variationId;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $optionIds;

    /** @var list<array{attribute: string, option: string}> */
    #[ORM\Column(type: 'json')]
    private array $options;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sku;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $unitPrice;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $total;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $tax;

    #[ORM\Column]
    private bool $ships;

    /** @var list<array{item: string, stockroom_id: int, quantity: int}> */
    #[ORM\Column(type: 'json')]
    private array $stockTaken;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
        private Order $order,
        LineDraft $draft,
        #[ORM\Column]
        private int $position,
    ) {
        if ($draft->quantity < 1) {
            throw new OrderRuleViolation('An order line has at least one item.', 'orillacart_invalid_quantity');
        }
        $this->productId = $draft->productId;
        $this->variationId = $draft->variationId;
        $this->optionIds = $draft->optionIds;
        $this->options = $draft->options;
        $this->name = mb_substr($draft->name, 0, 255);
        $this->sku = $draft->sku;
        $this->quantity = $draft->quantity;
        $this->unitPrice = $draft->unitPrice->toFixed();
        $this->total = $draft->total->toFixed();
        $this->tax = $draft->tax->toFixed();
        $this->ships = $draft->ships;
        $this->stockTaken = array_map(static fn(TakenItem $taken): array => ['item' => $taken->item, 'stockroom_id' => $taken->stockroomId, 'quantity' => $taken->quantity], $draft->taken);
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function variationId(): ?int
    {
        return $this->variationId;
    }

    /** @return list<int> */
    public function optionIds(): array
    {
        return $this->optionIds;
    }

    /** @return list<array{attribute: string, option: string}> */
    public function options(): array
    {
        return $this->options;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): Money
    {
        return Money::of($this->unitPrice);
    }

    public function total(): Money
    {
        return Money::of($this->total);
    }

    public function tax(): Money
    {
        return Money::of($this->tax);
    }

    public function ships(): bool
    {
        return $this->ships;
    }

    /** @return list<TakenItem> */
    public function stockTaken(): array
    {
        return array_map(static fn(array $taken): TakenItem => new TakenItem($taken['item'], $taken['stockroom_id'], $taken['quantity']), $this->stockTaken);
    }

    /** @internal for Order: the stock went back */
    public function forgetStock(): void
    {
        $this->stockTaken = [];
    }
}
