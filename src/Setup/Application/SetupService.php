<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

use OrillaCart\Settings\Application\SettingsArchive;
use OrillaCart\Setup\Application\Dto\ApplySetup;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\Dto\SetupPlanView;
use OrillaCart\Setup\Application\Dto\SetupResultView;
use OrillaCart\Setup\Application\Step\BusinessStep;
use OrillaCart\Setup\Application\Step\ShippingStep;
use OrillaCart\Setup\Domain\SetupRuleViolation;
use OrillaCart\Setup\Domain\SetupStepNotFound;
use OrillaCart\Setup\Domain\ShippingZone;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;

/**
 * The shop's setup: the owner answers each step's questions, in any order and over any number of visits; the
 * plan says what the answers would change, and nothing is saved until the owner applies it. Applying keeps the
 * settings as they were first, makes the changes in order, and stops at the first refused, so applying again
 * makes the rest.
 */
final readonly class SetupService
{
    public function __construct(
        private SetupSteps $steps,
        private SetupAnswersRepository $answers,
        private RequestMapper $input,
        private SettingsArchive $archive,
    ) {}

    /** @return array<string, array<array-key, mixed>|null> each step's answers as given, null for one not answered */
    public function answers(): array
    {
        $stored = $this->answers->load();
        $answers = [];
        foreach ($this->steps->all() as $step) {
            $answers[$step->key()] = $stored[$step->key()] ?? null;
        }

        return $answers;
    }

    /**
     * Keeps a step's answers, refused as a request would be when they are not valid.
     *
     * @param array<array-key, mixed> $answers
     *
     * @return array<string, array<array-key, mixed>|null>
     *
     * @throws SetupStepNotFound
     * @throws HttpException     for answers that are not valid
     */
    public function answer(string $key, array $answers): array
    {
        $step = $this->steps->step($key) ?? throw new SetupStepNotFound($key);
        $this->input->map($answers, $step->answers());
        $stored = $this->answers->load();
        if ($key === BusinessStep::KEY && ($stored[$key]['country'] ?? null) !== ($answers['country'] ?? null)) {
            // Another country asks other questions: its taxes, its law, where it ships.
            foreach ($this->steps->all() as $other) {
                if ($other instanceof AskedByCountry) {
                    unset($stored[$other->key()]);
                }
            }
        }
        $stored[$key] = $answers;
        $this->answers->save($stored);

        return $this->answers();
    }

    /**
     * The zones the shipping step prices, from the answers so far.
     *
     * @return list<array{id: string, countries: list<string>}>
     */
    public function shippingZones(ShippingStep $shipping): array
    {
        return array_map(static fn(ShippingZone $zone): array => ['id' => $zone->id, 'countries' => $zone->countries], $shipping->zones($this->current()));
    }

    public function plan(): SetupPlanView
    {
        return SetupPlanView::of($this->planned($this->current()));
    }

    /**
     * @throws RuleViolation when the plan is not the one the owner saw
     */
    public function apply(ApplySetup $command): SetupResultView
    {
        $answers = $this->current();
        $changes = $this->planned($answers);
        if (!hash_equals(SetupPlanView::fingerprint($changes), $command->fingerprint)) {
            throw new SetupRuleViolation('The shop changed since this plan was made; look at the plan again before applying it.', 'orillacart_setup_plan_changed');
        }
        $keys = array_map(static fn(PlannedChange $change): string => $change->key, $changes);
        foreach ($command->replace as $key) {
            if (!\in_array($key, $keys, true)) {
                throw new SetupRuleViolation('The plan has no change "%s" to replace with.', 'orillacart_setup_change_not_found', values: [$key]);
            }
        }

        $this->answers->keepBefore($this->archive->export());
        $applied = $kept = [];
        foreach ($changes as $change) {
            if ($change->replaces() && !\in_array($change->key, $command->replace, true)) {
                $kept[] = $change->key;
                continue;
            }
            try {
                ($this->steps->step($change->step) ?? throw new SetupStepNotFound($change->step))->apply($change, $answers);
            } catch (RuleViolation $e) {
                return new SetupResultView($applied, $kept, ['key' => $change->key, 'reason' => $e->text]);
            }
            $applied[] = $change->key;
        }

        return new SetupResultView($applied, $kept);
    }

    /** The answers stored, each step's as its DTO; a step's that no longer fit its questions count as not given. */
    private function current(): SetupAnswers
    {
        $stored = $this->answers->load();
        $sections = [];
        foreach ($this->steps->all() as $step) {
            $given = $stored[$step->key()] ?? null;
            if ($given === null) {
                continue;
            }
            try {
                $sections[$step->key()] = $this->input->map($given, $step->answers());
            } catch (HttpException) {
                continue;
            }
        }

        return new SetupAnswers($sections);
    }

    /** @return list<PlannedChange> */
    private function planned(SetupAnswers $answers): array
    {
        $changes = [];
        foreach ($this->steps->all() as $step) {
            foreach ($step->plan($answers) as $change) {
                $changes[] = $change;
            }
        }

        return $changes;
    }
}
