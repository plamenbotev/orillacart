<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * Result: the choices the shop's order filters offer, from what its orders have used.
 */
final readonly class OrderFacetsView
{
    /**
     * @param list<string>                               $shipping   the ways orders shipped, as they name them
     * @param list<string>                               $countries  billing and shipping countries
     * @param list<string>                               $currencies
     * @param list<array{value: string, label: string}>  $has        what an order may have: its own facts, then other areas' marks
     */
    public function __construct(
        public array $shipping,
        public array $countries,
        public array $currencies,
        public array $has,
    ) {}
}
