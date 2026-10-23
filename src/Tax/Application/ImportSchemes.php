<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Geography\Application\Dto\LocationView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\ImportDecision;
use OrillaCart\Tax\Application\Dto\ImportSchemesView;
use OrillaCart\Tax\Application\Dto\SaveImportSchemes;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Domain\ImportRegistration;
use OrillaCart\Tax\Domain\ImportRegistrationsRepository;
use OrillaCart\Tax\Domain\ImportScheme;

/**
 * Low-value goods shipped into a country with an import scheme (the EU's IOSS, the UK, Norway, Australia, New
 * Zealand), from a shop outside it: under a scheme the shop sells under, its goods are taxed at the destination at
 * checkout when the shop is registered and they are worth no more than the limit; otherwise they go untaxed, as
 * exports, and the customer pays the tax and duties at the border. Schemes the shop does not list change nothing.
 */
final readonly class ImportSchemes
{
    public function __construct(
        private ImportRegistrationsRepository $registrations,
        private SettingsService $shop,
        private GeographyService $geography,
    ) {}

    public function current(): ImportSchemesView
    {
        return new ImportSchemesView(
            $this->shop->current()->currency,
            array_map(static fn(ImportScheme $scheme): array => ['scheme' => $scheme->value, 'label' => $scheme->label(), 'legal_limit' => $scheme->limit(), 'currency' => $scheme->currency(), 'per_item' => $scheme->perItem(), 'example' => $scheme->numberExample()], ImportScheme::cases()),
            array_values(array_map(static fn(ImportRegistration $registration): array => ['scheme' => $registration->scheme->value, 'number' => $registration->number, 'limit' => $registration->limit], $this->registrations->load())),
        );
    }

    /**
     * @throws RuleViolation for a number or limit that cannot be, or a scheme listed twice
     */
    public function save(SaveImportSchemes $command): ImportSchemesView
    {
        $registrations = [];
        foreach ($command->schemes as $entry) {
            $scheme = ImportScheme::from($entry->scheme);
            if (isset($registrations[$scheme->value])) {
                throw new RuleViolation(\sprintf('%s is listed twice.', $scheme->label()), 'orillacart_duplicate_import_scheme');
            }
            $number = $entry->number === null || trim($entry->number) === '' ? null : ImportRegistration::normalize($entry->number);
            $registrations[$scheme->value] = new ImportRegistration($scheme, $number, $entry->limit);
        }
        $this->registrations->save($registrations);

        return $this->current();
    }

    /**
     * The scheme an order's goods come under, shipped to this address.
     *
     * @param Money $goods   what they are worth before shipping and tax, in the shop's currency
     * @param Money $highest the dearest of them, one unit, for limits per item
     */
    public function decide(?TaxAddress $shipping, Money $goods, Money $highest): ImportDecision
    {
        $registrations = $this->registrations->load();
        if ($shipping === null || $shipping->country === null || $registrations === []) {
            return new ImportDecision();
        }
        $to = $this->geography->locate($shipping->country, null);
        $shop = $this->shop->current();
        $from = $shop->shopCountry === null || $shop->shopCountry === '' ? null : $this->geography->locate($shop->shopCountry, null);
        foreach ($registrations as $registration) {
            if (!$to instanceof LocationView || !$registration->scheme->covers($to->country, $to->inEu)) {
                continue;
            }
            // A shop inside the scheme's countries sells there as at home.
            if ($from instanceof LocationView && $registration->scheme->covers($from->country, $from->inEu)) {
                return new ImportDecision();
            }
            $limit = Money::of($registration->limit);
            $within = ($registration->scheme->perItem() ? $highest : $goods)->compare($limit) <= 0;

            return $registration->number !== null && $within
                ? new ImportDecision($registration->scheme->value, $registration->number, true)
                : new ImportDecision($registration->scheme->value);
        }

        return new ImportDecision();
    }
}
