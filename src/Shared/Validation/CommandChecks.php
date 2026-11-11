<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Validation;

use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A command checked by its constraints as a request's body is, for a use case that takes one from elsewhere than a
 * request: an add-on's call (design 69).
 */
final readonly class CommandChecks
{
    public function __construct(private ValidatorInterface $validator) {}

    /** @throws \InvalidArgumentException naming what the shop cannot take, as its screens would */
    public function check(object $command): void
    {
        $violations = $this->validator->validate($command);
        if (\count($violations) === 0) {
            return;
        }
        $said = [];
        foreach ($violations as $violation) {
            $said[] = ($violation->getPropertyPath() === '' ? '' : $violation->getPropertyPath() . ': ') . $violation->getMessage();
        }

        throw new \InvalidArgumentException(implode('; ', $said));
    }
}
