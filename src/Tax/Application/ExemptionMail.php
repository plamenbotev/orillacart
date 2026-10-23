<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Tax\Application\Dto\TaxExemptionView;

/**
 * Tells the shop of a certificate a customer sent, and the customer what became of it and when it runs out.
 */
interface ExemptionMail
{
    public function received(TaxExemptionView $exemption, string $customerName, string $customerEmail): void;

    public function reviewed(TaxExemptionView $exemption, string $email, string $firstName): void;

    public function expiring(TaxExemptionView $exemption, string $email, string $firstName): void;
}
