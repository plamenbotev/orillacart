<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Packages\Application\ExtensionJobHandler;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\WordPress\ScheduledTask;
use Psr\Clock\ClockInterface;

/**
 * `every "daily" fn()`: once an hour, the extensions' schedules that are due are pushed as jobs. Recurrences:
 * hourly, twicedaily, daily, weekly. When each last ran is kept on the extension.
 */
final readonly class RunExtensionSchedules implements ScheduledTask
{
    private const array SECONDS = ['hourly' => 3600, 'twicedaily' => 43200, 'daily' => 86400, 'weekly' => 604800];

    public function __construct(private PackageService $packages, private Jobs $jobs, private ClockInterface $clock) {}

    public static function hook(): string
    {
        return 'orillacart_run_extension_schedules';
    }

    public static function recurrence(): string
    {
        return 'hourly';
    }

    public function run(): void
    {
        $now = $this->clock->now();
        foreach ($this->packages->loaded() as $loaded) {
            foreach ($loaded->manifest->ofKind('every') as $index => $declaration) {
                $recurrence = (string) $declaration['name'];
                $seconds = self::SECONDS[$recurrence] ?? null;
                if ($seconds === null || $declaration['handler'] === null) {
                    continue;
                }
                $key = $recurrence . '#' . $index;
                if ($this->packages->scheduleDue($loaded->package->slug(), $key, $seconds, $now)) {
                    $this->jobs->push(ExtensionJobHandler::TYPE, ['extension' => $loaded->package->slug(), 'method' => $declaration['handler'], 'arguments' => [], 'what' => 'every ' . $recurrence], $loaded->package->slug() . ':' . $key);
                }
            }
        }
    }
}
