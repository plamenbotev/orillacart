<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application\Dto;

/**
 * Who sells, as invoices name them: what the setup asks once for the shop and its invoices.
 */
final readonly class Seller
{
    public function __construct(
        public string $legalName,
        public string $address,
        public string $vatNumber,
        public string $companyNumber,
        public string $phone,
        public string $email,
    ) {}
}
