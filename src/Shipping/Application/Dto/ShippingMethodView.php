<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Extension\Setting;

/**
 * A shipping method an add-on brings, as the carrier screen offers it.
 */
final readonly class ShippingMethodView
{
    /**
     * @param list<Setting> $fields
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $fields,
    ) {}
}
