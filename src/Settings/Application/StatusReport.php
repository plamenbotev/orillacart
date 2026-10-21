<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

/**
 * How the shop stands, for the Status screen and a support request: its checks' findings, and what it runs on.
 */
interface StatusReport
{
    /**
     * @return array{
     *     checks: list<array{id: string, check: string, status: string, finding: string, description: string, action_url: string, action_label: string}>,
     *     sections: list<array{title: string, fields: list<array{label: string, value: string}>}>
     * }
     */
    public function report(): array;
}
