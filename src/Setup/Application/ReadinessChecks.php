<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

interface ReadinessChecks
{
    /** @return list<ReadinessCheck> */
    public function all(): array;
}
