<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Setup\Application\SetupService;

/**
 * Applying the setup, as the plan it would apply: what is made, what replaces what there is and what is kept, and
 * the warnings, as the setup screen shows them before its own button.
 */
final readonly class SetupPlanDescription implements CallDescription
{
    public const string TOOL = 'apply_setup';

    public function __construct(
        private SetupService $setup,
    ) {}

    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string
    {
        if ($tool->name !== self::TOOL) {
            return null;
        }
        $plan = $this->setup->plan();
        if (($arguments['fingerprint'] ?? null) !== $plan->fingerprint) {
            return 'The shop changed since the assistant read the plan, so applying it will be refused and nothing will change.';
        }
        $replace = \is_array($arguments['replace'] ?? null) ? $arguments['replace'] : [];
        $lines = ['Applying the setup does this:'];
        foreach ($plan->changes as $change) {
            $lines[] = '• ' . $change['summary'];
            foreach ($change['details'] as $detail) {
                $lines[] = '   – ' . $detail;
            }
            if ($change['existing'] !== null) {
                $lines[] = \in_array($change['key'], $replace, true)
                    ? '   Replaces what there is: ' . $change['existing']
                    : '   Not done: what there is stays (' . $change['existing'] . ')';
            }
            if ($change['warning'] !== null) {
                $lines[] = '   ⚠ ' . $change['warning'];
            }
        }

        return implode("\n", $lines);
    }
}
