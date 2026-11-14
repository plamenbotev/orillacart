<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\Retention;

use OrillaCart\Packages\Application\Recordings;
use OrillaCart\Packages\Application\RunLog;
use OrillaCart\Settings\Application\KeptHistory;
use Psr\Clock\ClockInterface;

/**
 * The log of extensions' runs, as long as the shop keeps history. Recordings hold what a run saw, customers' data
 * among it: they go after a day, whatever the shop keeps of history.
 */
final readonly class ForgetExtensionRuns implements KeptHistory
{
    public function __construct(
        private RunLog $runs,
        private Recordings $recordings,
        private ClockInterface $clock,
    ) {}

    public function forgetBefore(\DateTimeImmutable $before): void
    {
        $this->runs->forgetBefore($before);
        $this->recordings->forgetBefore($this->clock->now()->sub(new \DateInterval('PT' . Recordings::KEPT_HOURS . 'H')));
    }
}
