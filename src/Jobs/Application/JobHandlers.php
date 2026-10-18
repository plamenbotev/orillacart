<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Application;

/**
 * Every job type's handler, found when a job of the type is pushed or run.
 */
interface JobHandlers
{
    /**
     * @throws \LogicException for a type no handler does
     */
    public function get(string $type): JobHandler;

    /** @return list<string> every job type, by name */
    public function types(): array;

    /** @return list<string> every lane, by name */
    public function queues(): array;
}
