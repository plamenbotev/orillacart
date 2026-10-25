<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

/**
 * The setup's steps, in their order.
 */
interface SetupSteps
{
    /** @return list<SetupStep> */
    public function all(): array;

    public function step(string $key): ?SetupStep;
}
