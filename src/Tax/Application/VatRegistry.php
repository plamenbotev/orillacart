<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Tax\Application\Dto\VatCheck;

/**
 * The EU's register of VAT numbers (VIES).
 */
interface VatRegistry
{
    /**
     * @param string $prefix the VIES country code: the ISO code, but EL for Greece and XI for Northern Ireland
     * @param string $number the number without its prefix
     */
    public function check(string $prefix, string $number): VatCheck;
}
