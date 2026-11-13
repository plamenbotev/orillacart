<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress;

use OrillaCart\Agents\Application\AgentCheckouts;
use OrillaCart\Shared\WordPress\ScheduledTask;

/** Daily: forgets shoppers' agents' checkout sessions a day past their end, with the buyer's details they kept. */
final readonly class ForgetAgentCheckouts implements ScheduledTask
{
    public function __construct(private AgentCheckouts $checkouts) {}

    public static function hook(): string
    {
        return 'orillacart_forget_agent_checkouts';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->checkouts->forgetOld();
    }
}
