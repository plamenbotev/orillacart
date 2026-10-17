<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

final readonly class CustomerPageView
{
    /**
     * @param list<CustomerView> $customers
     */
    public function __construct(
        public array $customers,
        public int $total,
        public int $page,
        public int $pages,
    ) {}
}
