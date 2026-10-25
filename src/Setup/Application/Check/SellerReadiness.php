<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Check;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\Dto\Readiness;
use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Setup\Domain\LegalProfiles;
use OrillaCart\Shared\Domain\Text;

/**
 * Who sells: invoices must name the seller and its address, the shop where it sells from; and e-invoices where
 * the country's law asks for them of business sales.
 */
final readonly class SellerReadiness implements ReadinessCheck
{
    public function __construct(
        private Invoices $invoices,
        private SettingsService $settings,
    ) {}

    public function check(): array
    {
        $found = [];
        $seller = $this->invoices->settings();
        $country = $this->settings->current()->shopCountry;
        if ($country === null) {
            $found[] = Readiness::stop('seller.country', Text::of('The shop does not say where it sells from.'), 'setup');
        }
        if (trim($seller->legalName) === '' || trim($seller->address) === '') {
            $found[] = Readiness::stop('seller.invoices', Text::of('Invoices do not name the seller and its address.'), 'settings');
        }
        $duty = $country === null ? null : LegalProfiles::of($country)->eInvoices;
        if ($duty !== null) {
            $found[] = !$duty->met
                ? Readiness::advice('seller.e_invoices', Text::of('Invoices to businesses in %1$s must go %2$s, which the shop\'s own invoices do not: use invoicing software that does, or an add-on.', $country, $duty->how))
                : ($seller->eInvoices === 'none' ? Readiness::advice('seller.e_invoices', Text::of('Invoices to businesses in %1$s must be electronic (%2$s): turn on e-invoices in Settings → Invoices.', $country, $duty->how), 'settings') : null);
        }

        return array_values(array_filter($found));
    }
}
