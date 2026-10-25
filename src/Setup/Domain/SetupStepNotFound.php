<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class SetupStepNotFound extends NotFound
{
    public function __construct(public readonly string $step)
    {
        parent::__construct('The setup has no step "%s".', 'orillacart_setup_step_not_found', values: [$step]);
    }
}
