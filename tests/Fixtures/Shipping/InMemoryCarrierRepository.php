<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Shipping;

use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\ShippingRate;

/**
 * Keeps carriers in memory and numbers carriers and rates on save, as the database does.
 */
final class InMemoryCarrierRepository implements CarrierRepository
{
    /** @var array<int, Carrier> */
    private array $carriers = [];
    private int $nextCarrierId = 1;
    private int $nextRateId = 1;

    public function all(): array
    {
        $carriers = array_values($this->carriers);
        usort($carriers, static fn(Carrier $a, Carrier $b): int => [$a->position(), $a->name(), $a->id()] <=> [$b->position(), $b->name(), $b->id()]);

        return $carriers;
    }

    public function find(int $id): ?Carrier
    {
        return $this->carriers[$id] ?? null;
    }

    public function save(Carrier $carrier): void
    {
        $id = new \ReflectionProperty(Carrier::class, 'id');
        if ($id->getValue($carrier) === null) {
            $id->setValue($carrier, $this->nextCarrierId++);
        }
        $rateId = new \ReflectionProperty(ShippingRate::class, 'id');
        foreach ($carrier->rates() as $rate) {
            if (!$rate->isSaved()) {
                $rateId->setValue($rate, $this->nextRateId++);
            }
        }
        $this->carriers[$carrier->id()] = $carrier;
    }

    public function remove(Carrier $carrier): void
    {
        unset($this->carriers[$carrier->id()]);
    }
}
