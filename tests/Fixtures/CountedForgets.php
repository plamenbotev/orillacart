<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures;

use OrillaCart\Shared\Persistence\LoadedEntities;

/** Counts how often the loaded entities were forgotten: in memory there is nothing to forget. */
final class CountedForgets implements LoadedEntities
{
    public int $times = 0;

    public function forget(): void
    {
        ++$this->times;
    }
}
