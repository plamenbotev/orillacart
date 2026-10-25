<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\Dto\BusinessAnswers;
use OrillaCart\Setup\Application\Dto\SellingAnswers;
use OrillaCart\Setup\Application\SetupAnswers;

/**
 * What the later steps go by: the answers given so far, or the shop as it is where they were not given.
 */
final readonly class ShopFacts
{
    public function __construct(
        private SettingsService $settings,
        private Invoices $invoices,
    ) {}

    public function country(SetupAnswers $answers): ?string
    {
        return $answers->of(BusinessStep::KEY, BusinessAnswers::class)->country ?? $this->settings->current()->shopCountry;
    }

    /** @return array{string, string} the seller's name and address, on one line */
    public function seller(SetupAnswers $answers): array
    {
        $business = $answers->of(BusinessStep::KEY, BusinessAnswers::class);
        if ($business !== null) {
            return [trim($business->legalName), implode(', ', array_filter([trim($business->street), trim(trim($business->postcode) . ' ' . trim($business->city))], static fn(string $part): bool => $part !== ''))];
        }
        $invoices = $this->invoices->settings();

        return [$invoices->legalName, str_replace("\n", ', ', $invoices->address)];
    }

    public function selling(SetupAnswers $answers): SellingAnswers
    {
        return $answers->of(SellingStep::KEY, SellingAnswers::class) ?? new SellingAnswers(SellingAnswers::CONSUMERS, 'world');
    }
}
