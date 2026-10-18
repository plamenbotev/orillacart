<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * How many of one item a stockroom holds. Below zero when orders took more than it held, as backorders: the shop
 * owes that many.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_levels')]
#[ORM\UniqueConstraint(name: 'stockroom_item', columns: ['stockroom_id', 'item_kind', 'item_id'])]
#[ORM\Index(name: 'item', columns: ['item_kind', 'item_id'])]
class StockLevel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: StockItemKind::class)]
    private StockItemKind $itemKind;

    #[ORM\Column]
    private int $itemId;

    #[ORM\Column]
    private int $quantity;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Stockroom::class)]
        #[ORM\JoinColumn(name: 'stockroom_id', nullable: false, onDelete: 'CASCADE')]
        private Stockroom $stockroom,
        StockItem $item,
        int $quantity,
    ) {
        $this->itemKind = $item->kind;
        $this->itemId = $item->id;
        $this->set($quantity);
    }

    public function stockroom(): Stockroom
    {
        return $this->stockroom;
    }

    public function item(): StockItem
    {
        return new StockItem($this->itemKind, $this->itemId);
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function set(int $quantity): void
    {
        if ($quantity < 0) {
            throw new InventoryRuleViolation('A stock level cannot be below zero.', 'orillacart_invalid_quantity');
        }
        $this->quantity = $quantity;
    }

    /**
     * @internal for Stock::take()
     */
    public function take(int $quantity): void
    {
        $this->set($this->quantity - $quantity);
    }

    /**
     * Takes more than the stockroom holds; the level goes below zero by what is owed.
     *
     * @internal for Stock::take()
     */
    public function owe(int $quantity): void
    {
        $this->quantity -= $quantity;
    }

    /**
     * Puts back what an order took; a level below zero owes that much less.
     *
     * @internal for Stock::putBack()
     */
    public function putBack(int $quantity): void
    {
        $this->quantity += $quantity;
    }
}
