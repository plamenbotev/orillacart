<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Application\Dto;

use OrillaCart\Geography\Domain\State;

final readonly class StateView
{
    public function __construct(
        public string $code,
        public string $name,
    ) {}

    public static function fromState(State $state): self
    {
        return new self($state->code, $state->name);
    }
}
