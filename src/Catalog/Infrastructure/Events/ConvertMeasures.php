<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Events;

use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Extension\Shipping\Units;
use OrillaCart\Settings\Domain\SettingsChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The shop changed its weight or size unit: the products' measures are converted, so they mean what they did.
 */
#[AsEventListener]
final readonly class ConvertMeasures
{
    public function __construct(private ProductService $service) {}

    public function __invoke(SettingsChanged $event): void
    {
        $weight = Units::weightFactor($event->previous->weightUnit->value, $event->current->weightUnit->value);
        $length = Units::lengthFactor($event->previous->dimensionUnit->value, $event->current->dimensionUnit->value);
        if ($weight !== 1.0 || $length !== 1.0) {
            $this->service->convertMeasures($weight, $length);
        }
    }
}
