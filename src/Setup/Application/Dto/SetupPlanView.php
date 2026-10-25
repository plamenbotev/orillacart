<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * The plan as the owner reads it. Applying it names its fingerprint, so a plan the shop has moved on from since
 * (another manager changed a setting) is not applied unseen.
 */
final readonly class SetupPlanView
{
    /**
     * @param list<array{key: string, step: string, summary: Text, existing: ?Text, warning: ?Text}> $changes
     */
    public function __construct(
        public array $changes,
        public string $fingerprint,
    ) {}

    /** @param list<PlannedChange> $changes */
    public static function of(array $changes): self
    {
        return new self(
            array_map(static fn(PlannedChange $change): array => ['key' => $change->key, 'step' => $change->step, 'summary' => $change->summary, 'existing' => $change->existing, 'warning' => $change->warning], $changes),
            self::fingerprint($changes),
        );
    }

    /** @param list<PlannedChange> $changes */
    public static function fingerprint(array $changes): string
    {
        return hash('sha256', serialize(array_map(
            static fn(PlannedChange $change): array => [$change->key, (string) $change->summary, (string) $change->existing, $change->data],
            $changes,
        )));
    }
}
