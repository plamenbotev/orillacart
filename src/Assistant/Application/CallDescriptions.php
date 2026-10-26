<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

/**
 * Every description of calls the container found.
 */
interface CallDescriptions
{
    /** @return list<CallDescription> */
    public function all(): array;
}
