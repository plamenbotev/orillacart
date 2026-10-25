<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Setup\Application\Dto\OpeningAnswers;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\SetupAnswers;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Storefront\Application\ShopOpening;

/**
 * Before anything else: visitors see "Opening soon" until the setup's last step opens the shop, so no one orders
 * from a shop half set up. The owner chooses it in the review; for a shop set up before, it starts unticked.
 */
final readonly class OpeningStep implements SetupStep
{
    public const string KEY = 'opening';

    public function __construct(private ShopOpening $opening) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function position(): int
    {
        return 0;
    }

    public function answers(): string
    {
        return OpeningAnswers::class;
    }

    public function plan(SetupAnswers $answers): array
    {
        $opening = $answers->of(self::KEY, OpeningAnswers::class);
        if ($opening === null || !$opening->opensSoon || $this->opening->opensSoon()) {
            return [];
        }

        return [new PlannedChange('opening.soon', self::KEY, Text::of('Visitors see "Opening soon" until you open the shop; you see the real shop, to try it.'))];
    }

    public function apply(PlannedChange $change, SetupAnswers $answers): void
    {
        $this->opening->close();
    }
}
