<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

final class UnknownState extends RuleViolation
{
    public function __construct(public readonly string $country, public readonly string $state)
    {
        parent::__construct('"%1$s" is not a state of %2$s.', 'orillacart_unknown_state', values: [$state, $country]);
    }
}
