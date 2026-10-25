<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Infrastructure;

use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Setup\Application\SetupSteps;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The steps the container found by their tag, by position.
 */
final readonly class TaggedSetupSteps implements SetupSteps
{
    /** @var list<SetupStep> */
    private array $steps;

    /** @param iterable<SetupStep> $steps */
    public function __construct(
        #[AutowireIterator(SetupStep::TAG)]
        iterable $steps,
    ) {
        $steps = iterator_to_array($steps, false);
        usort($steps, static fn(SetupStep $a, SetupStep $b): int => $a->position() <=> $b->position());
        $this->steps = $steps;
    }

    public function all(): array
    {
        return $this->steps;
    }

    public function step(string $key): ?SetupStep
    {
        foreach ($this->steps as $step) {
            if ($step->key() === $key) {
                return $step;
            }
        }

        return null;
    }
}
