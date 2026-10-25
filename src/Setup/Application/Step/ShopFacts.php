<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\Dto\BusinessAnswers;
use OrillaCart\Setup\Application\Dto\SellingAnswers;
use OrillaCart\Setup\Application\SetupAnswers;

/**
 * What the later steps go by: the answers given so far, or the shop as it is where they were not given.
 */
final readonly class ShopFacts
{
    public function __construct(private SettingsService $settings) {}

    public function country(SetupAnswers $answers): ?string
    {
        return $answers->of(BusinessStep::KEY, BusinessAnswers::class)->country ?? $this->settings->current()->shopCountry;
    }

    public function selling(SetupAnswers $answers): SellingAnswers
    {
        return $answers->of(SellingStep::KEY, SellingAnswers::class) ?? new SellingAnswers(SellingAnswers::CONSUMERS, 'world');
    }
}
