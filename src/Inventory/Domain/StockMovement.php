<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * One move of an item's level in a stockroom: by how much, why, for which order or by whom, and the level it
 * left. Kept when the stockroom goes, by its name then; the shop's stock history and reports read them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_movements')]
#[ORM\Index(name: 'movement_item', columns: ['item_kind', 'item_id', 'moved_at'])]
#[ORM\Index(name: 'movement_stockroom', columns: ['stockroom_id', 'moved_at'])]
#[ORM\Index(name: 'movement_order', columns: ['order_id'])]
#[ORM\Index(name: 'movement_at', columns: ['moved_at'])]
class StockMovement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: StockItemKind::class)]
    private StockItemKind $itemKind;

    #[ORM\Column]
    private int $itemId;

    public function __construct(
        StockItem $item,
        #[ORM\Column]
        private int $stockroomId,
        #[ORM\Column(length: StockroomDetails::MAX_NAME_LENGTH)]
        private string $stockroom,
        /** Up when positive, down when negative. */
        #[ORM\Column]
        private int $delta,
        /** The stockroom's level after it; below zero by what is owed. */
        #[ORM\Column(name: 'level_after')]
        private int $after,
        #[ORM\Column(length: 20, enumType: StockReason::class)]
        private StockReason $reason,
        #[ORM\Column(nullable: true)]
        private ?int $orderId,
        #[ORM\Column(nullable: true)]
        private ?int $userId,
        #[ORM\Column(name: 'moved_at')]
        private \DateTimeImmutable $at,
    ) {
        $this->itemKind = $item->kind;
        $this->itemId = $item->id;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The movement has no id until it is saved.');
    }

    public function item(): StockItem
    {
        return new StockItem($this->itemKind, $this->itemId);
    }

    public function stockroomId(): int
    {
        return $this->stockroomId;
    }

    public function stockroom(): string
    {
        return $this->stockroom;
    }

    public function delta(): int
    {
        return $this->delta;
    }

    public function after(): int
    {
        return $this->after;
    }

    public function reason(): StockReason
    {
        return $this->reason;
    }

    public function orderId(): ?int
    {
        return $this->orderId;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function at(): \DateTimeImmutable
    {
        return $this->at;
    }
}
