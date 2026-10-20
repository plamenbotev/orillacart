<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A place the shop keeps stock and ships from.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stockrooms')]
class Stockroom
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: StockroomDetails::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column]
    private int $minDeliveryDays;

    #[ORM\Column]
    private int $maxDeliveryDays;

    #[ORM\Column]
    private bool $active;

    #[ORM\Column(length: 10, enumType: DeliveryUnit::class, options: ['default' => 'days'])]
    private DeliveryUnit $deliveryUnit = DeliveryUnit::Days;

    #[ORM\Column(nullable: true)]
    private ?int $lowStockThreshold = null;

    public function __construct(StockroomDetails $details)
    {
        $this->change($details);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The stockroom has no id until it is saved.');
    }

    public function details(): StockroomDetails
    {
        return new StockroomDetails($this->name, $this->minDeliveryDays, $this->maxDeliveryDays, $this->active, $this->description, $this->deliveryUnit, $this->lowStockThreshold);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function maxDeliveryDays(): int
    {
        return $this->maxDeliveryDays;
    }

    public function change(StockroomDetails $details): void
    {
        $this->name = trim($details->name);
        $this->description = $details->description;
        $this->minDeliveryDays = $details->minDeliveryDays;
        $this->maxDeliveryDays = $details->maxDeliveryDays;
        $this->active = $details->active;
        $this->deliveryUnit = $details->deliveryUnit;
        $this->lowStockThreshold = $details->lowStockThreshold;
    }
}
