<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\Events;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Reports\Application\RebuildReports;
use OrillaCart\Settings\Domain\SettingsChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Counts every order again when the shop changes the day orders count on: the facts kept carry the old day.
 */
final readonly class CountAgainOnReportDay
{
    public function __construct(private Jobs $jobs) {}

    #[AsEventListener]
    public function __invoke(SettingsChanged $event): void
    {
        if ($event->previous->reportDay !== $event->current->reportDay) {
            $this->jobs->push(RebuildReports::TYPE, ['after' => 0], RebuildReports::KEY);
        }
    }
}
