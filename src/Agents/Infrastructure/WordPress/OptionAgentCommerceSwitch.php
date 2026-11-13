<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress;

use OrillaCart\Agents\Application\AgentCommerceSwitch;

final readonly class OptionAgentCommerceSwitch implements AgentCommerceSwitch
{
    public const string OPTION = 'orillacart_agent_commerce';

    public function on(): bool
    {
        return get_option(self::OPTION) === '1';
    }

    public function set(bool $on): void
    {
        update_option(self::OPTION, $on ? '1' : '0', true);
    }
}
