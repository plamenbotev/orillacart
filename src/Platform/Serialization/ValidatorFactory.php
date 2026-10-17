<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Serialization;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The validator that checks command and query DTOs against their #[Assert\...] attributes.
 */
final class ValidatorFactory
{
    public function create(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
