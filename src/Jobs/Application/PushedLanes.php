<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

/**
 * The lanes a request pushed jobs to, for the one piece of work after its response that runs a few of them.
 */
final class PushedLanes
{
    /** @var array<string, true> */
    private array $lanes = [];

    public function add(string $lane): void
    {
        $this->lanes[$lane] = true;
    }

    /** @return list<string> the lanes pushed to since the last take, which starts over */
    public function take(): array
    {
        $lanes = array_map(strval(...), array_keys($this->lanes));
        $this->lanes = [];

        return $lanes;
    }
}
