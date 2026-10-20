<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * The delivery days a way to ship lets the customer choose among, and whether they must.
 */
final readonly class DeliveryChoiceView
{
    /**
     * @param list<DeliveryDayView> $days earliest first; the days whose slots are all full are left out
     */
    public function __construct(
        public bool $required,
        public array $days,
    ) {}
}
