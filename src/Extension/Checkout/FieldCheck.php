<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * Checks a checkout field's answer beyond its kind and length. It is asked only for an answer given; a required
 * field left empty is refused before.
 */
interface FieldCheck
{
    /**
     * @return string|null why the answer is refused, for the customer, or null to accept it
     */
    public function check(string $answer, FieldContext $context): ?string;
}
