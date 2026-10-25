<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Check;

use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\Dto\Readiness;
use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Setup\Domain\LegalProfiles;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Storefront\Application\LegalSettingsService;

/**
 * The pages the law of the shop's country asks for, published: the terms of sale, the right of withdrawal and
 * the legal notice where consumers have those rights; and checkout's button saying that ordering means paying.
 */
final readonly class LegalReadiness implements ReadinessCheck
{
    public function __construct(
        private LegalSettingsService $legal,
        private SettingsService $settings,
    ) {}

    public function check(): array
    {
        $country = $this->settings->current()->shopCountry;
        if ($country === null) {
            return [];
        }
        $profile = LegalProfiles::of($country);
        $legal = $this->legal->current();
        $published = $this->legal->publishedPages();
        $found = [];
        $page = static fn(?int $id): bool => $id !== null && \in_array($id, $published, true);
        if (!$page($legal->terms)) {
            $found[] = $profile->consumerCheckout
                ? Readiness::stop('legal.terms', Text::of('The terms of sale are not published.'), 'settings')
                : Readiness::advice('legal.terms', Text::of('The terms of sale are not published.'), 'settings');
        }
        if ($profile->withdrawalDays !== null && !$page($legal->withdrawal)) {
            $found[] = Readiness::stop('legal.withdrawal', Text::of('The right of withdrawal is not published: consumers must be told of it before they order.'), 'settings');
        }
        if ($profile->legalNotice && !$page($legal->legalNotice)) {
            $found[] = Readiness::stop('legal.notice', Text::of('The legal notice naming the seller is not published.'), 'settings');
        }
        if ($profile->consumerCheckout && $legal->orderButton === 'place_order') {
            $found[] = Readiness::stop('legal.button', Text::of('Checkout\'s button says "Place order": the law asks it to say that ordering means paying, such as "Buy now".'), 'settings');
        }
        if (!$page($legal->delivery)) {
            $found[] = Readiness::advice('legal.delivery', Text::of('No page tells customers where the shop ships and what it costs.'), 'settings');
        }

        return $found;
    }
}
