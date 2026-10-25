<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

use OrillaCart\Setup\Application\Dto\Readiness;
use OrillaCart\Setup\Application\Dto\ReadinessView;

/**
 * Whether the shop is ready to sell, asked of every check: the setup's last step, and the Status screen after.
 */
final readonly class ShopReadiness
{
    public function __construct(private ReadinessChecks $checks) {}

    public function report(): ReadinessView
    {
        $found = [];
        foreach ($this->checks->all() as $check) {
            foreach ($check->check() as $readiness) {
                $found[] = $readiness;
            }
        }
        $stops = array_values(array_filter($found, static fn(Readiness $readiness): bool => $readiness->stops));
        $advice = array_values(array_filter($found, static fn(Readiness $readiness): bool => !$readiness->stops));

        return new ReadinessView($stops === [], $stops, $advice);
    }
}
