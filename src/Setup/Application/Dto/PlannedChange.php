<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * A change the setup would make, as the owner reads it before anything is saved: "Customers in Germany pay 19%
 * MwSt., included in prices." What is there already is kept unless the owner chooses to replace it.
 */
final readonly class PlannedChange
{
    /**
     * @param array<string, mixed> $data    what the step needs to make the change; part of the plan's fingerprint
     * @param list<Text>           $details more of what it does, a line each
     */
    public function __construct(
        /** Stable within the plan: "tax.group.standard", "settings.currency". */
        public string $key,
        public string $step,
        public Text $summary,
        /** What there is already, which the change would replace; null when it adds or only fills in. */
        public ?Text $existing = null,
        public array $data = [],
        /** What the owner should know before applying it: a VAT number the EU does not know. Not part of the fingerprint. */
        public ?Text $warning = null,
        public array $details = [],
    ) {}

    /** Whether the owner chooses: keep what there is (the default), or replace it. */
    public function replaces(): bool
    {
        return $this->existing !== null;
    }
}
