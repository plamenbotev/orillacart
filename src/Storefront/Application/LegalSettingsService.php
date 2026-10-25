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

    /** @return list<int> the legal pages that are published */
    public function publishedPages(): array
    {
        $settings = $this->settings->load();

        return array_values(array_filter([$settings->terms, $settings->withdrawal, $settings->delivery, $settings->legalNotice], fn(?int $id): bool => $id !== null && $this->settings->published($id) !== null));
    }

    /**
     * The terms of sale and the right of withdrawal, published, for the order confirmation to carry: the law asks
     * that a consumer gets them on a durable medium, such as an email.
     *
     * @return list<array{title: string, html: string}>
     */
    public function confirmationTexts(): array
    {
        $settings = $this->settings->load();
        $texts = [];
        foreach ([$settings->terms, $settings->withdrawal] as $id) {
            $page = $id === null ? null : $this->settings->published($id);
            if ($page !== null) {
                $texts[] = $page;
            }
        }

        return $texts;
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

    /**
     * Makes the page of one of the legal parts (terms, withdrawal, delivery, legal_notice) and links it there.
     */
    public function makePage(string $part, string $title, string $content, bool $publish): LegalSettingsView
    {
        $id = $this->settings->makePage($title, $content, $publish);
        $now = $this->settings->load();
        $page = static fn(string $which, ?int $current): ?int => $which === $part ? $id : $current;
        $settings = new LegalSettings($page('terms', $now->terms), $page('withdrawal', $now->withdrawal), $page('delivery', $now->delivery), $page('legal_notice', $now->legalNotice), $now->acceptTerms, $now->orderButton);
        $this->settings->save($settings);

        return LegalSettingsView::fromSettings($settings);
    }

    /**
     * Whether checkout asks to accept the terms, and what its button says; the pages as they are.
     *
     * @throws StorefrontRuleViolation for accepting terms without their page
     */
    public function checkout(bool $acceptTerms, OrderButton $button): LegalSettingsView
    {
        $now = $this->settings->load();
        $settings = new LegalSettings($now->terms, $now->withdrawal, $now->delivery, $now->legalNotice, $acceptTerms, $button);
        $this->settings->save($settings);

        return LegalSettingsView::fromSettings($settings);
    }
}
