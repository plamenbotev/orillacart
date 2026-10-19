<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Jobs;

use OrillaCart\Jobs\Application\JobContext;

/**
 * Runs jobs as they come; counts how often.
 */
final class PlainJobContext implements JobContext
{
    public int $runs = 0;

    public function run(\Closure $work): mixed
    {
        ++$this->runs;

        return $work();
    }
}
