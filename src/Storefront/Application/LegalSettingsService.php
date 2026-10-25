<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

use OrillaCart\Storefront\Application\Dto\LegalSettingsView;
use OrillaCart\Storefront\Application\Dto\UpdateLegalSettings;
use OrillaCart\Storefront\Domain\LegalSettings;
use OrillaCart\Storefront\Domain\LegalSettingsRepository;
use OrillaCart\Storefront\Domain\OrderButton;
use OrillaCart\Storefront\Domain\StorefrontRuleViolation;

/**
 * The shop's legal pages and what checkout asks before an order.
 */
final readonly class LegalSettingsService
{
    public function __construct(private LegalSettingsRepository $settings) {}

    public function current(): LegalSettingsView
    {
        return LegalSettingsView::fromSettings($this->settings->load());
    }

    /** Whether checkout asks the customer to accept the terms of sale. */
    public function asksToAcceptTerms(): bool
    {
        return $this->settings->load()->acceptTerms;
    }

    /**
     * @throws StorefrontRuleViolation for an id that is not a page, or accepting terms without their page
     */
    public function update(UpdateLegalSettings $command): LegalSettingsView
    {
        foreach ([$command->terms, $command->withdrawal, $command->delivery, $command->legalNotice] as $id) {
            if ($id !== null && !$this->settings->isPage($id)) {
                throw new StorefrontRuleViolation('Page %d is not a page of the site.', 'orillacart_unknown_page', values: [$id]);
            }
        }
        $settings = new LegalSettings($command->terms, $command->withdrawal, $command->delivery, $command->legalNotice, $command->acceptTerms, OrderButton::from($command->orderButton));
        $this->settings->save($settings);

        return LegalSettingsView::fromSettings($settings);
    }
}
