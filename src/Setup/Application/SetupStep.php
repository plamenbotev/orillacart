<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

use OrillaCart\Setup\Application\Dto\PlannedChange;

/**
 * One step of the shop's setup: the questions it asks (a DTO of answers), what the answers would change, and the
 * change made. Plans read the shop as it is, so a second setup adds only what is missing. Tagged by the Kernel,
 * so the container finds every one.
 */
interface SetupStep
{
    public const string TAG = 'orillacart.setup_step';

    /** The step's name in the API and in the stored answers: "business", "tax". */
    public function key(): string;

    /** Where it comes in the setup: lower first. */
    public function position(): int;

    /** @return class-string the DTO its answers map to */
    public function answers(): string;

    /**
     * What the answers would change, in the order they are made. Nothing is saved.
     *
     * @return list<PlannedChange>
     */
    public function plan(SetupAnswers $answers): array;

    /**
     * Makes one change of the plan; one that exists and was not chosen to replace is never given.
     *
     * @throws \OrillaCart\Shared\Domain\RuleViolation
     */
    public function apply(PlannedChange $change, SetupAnswers $answers): void;
}
