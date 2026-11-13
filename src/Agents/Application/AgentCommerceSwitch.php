<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Application;

/** Whether the shop sells through shoppers' agents: the owner switches it on in Shop › Integrations. */
interface AgentCommerceSwitch
{
    public function on(): bool;

    public function set(bool $on): void;
}
