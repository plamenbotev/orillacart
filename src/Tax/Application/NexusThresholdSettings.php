<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\NexusThresholdsView;
use OrillaCart\Tax\Application\Dto\SaveNexusThresholds;
use OrillaCart\Tax\Domain\NexusThreshold;
use OrillaCart\Tax\Domain\NexusThresholds;
use OrillaCart\Tax\Domain\NexusThresholdsRepository;

/**
 * The economic nexus thresholds the shop goes by, which the nexus report measures its sales in each US state
 * against.
 */
final readonly class NexusThresholdSettings
{
    public function __construct(private NexusThresholdsRepository $thresholds) {}

    public function current(): NexusThresholdsView
    {
        return NexusThresholdsView::fromThresholds($this->thresholds->load());
    }

    /**
     * @throws RuleViolation for a threshold that cannot be, or a state listed twice
     */
    public function save(SaveNexusThresholds $command): NexusThresholdsView
    {
        $states = [];
        foreach ($command->states as $state) {
            if ($state->state === null || isset($states[$state->state])) {
                throw new RuleViolation('List each state once, with its code.', 'orillacart_invalid_region');
            }
            $states[$state->state] = new NexusThreshold(self::amount($state->sales), $state->orders);
        }
        $thresholds = new NexusThresholds(new NexusThreshold(self::amount($command->everywhere->sales), $command->everywhere->orders), $states);
        $this->thresholds->save($thresholds);

        return NexusThresholdsView::fromThresholds($thresholds);
    }

    /** "100000.00" as "100000": what the shop typed, without the cents it does not need. */
    private static function amount(?string $sales): ?string
    {
        return $sales === null ? null : (preg_replace('/\.0{1,2}$/', '', $sales) ?? $sales);
    }
}
