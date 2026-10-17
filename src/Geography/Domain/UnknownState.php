<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

final class UnknownState extends RuleViolation
{
    public function __construct(public readonly string $country, public readonly string $state)
    {
        parent::__construct(\sprintf('"%s" is not a state of %s.', $state, $country), 'orillacart_unknown_state');
    }
}
