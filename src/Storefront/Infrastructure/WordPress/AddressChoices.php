<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Geography\Application\Dto\CountryView;
use OrillaCart\Geography\Application\Dto\StateView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;

/**
 * The countries and states address forms offer (those the shop sells to), and country names for showing
 * addresses (every country: an order may be older than the shop's choice).
 */
final readonly class AddressChoices
{
    public function __construct(
        private GeographyService $geography,
        private SettingsService $settings,
    ) {}

    /**
     * @return array{countries: list<CountryView>, states: array<string, list<StateView>>}
     */
    public function all(): array
    {
        $sellTo = $this->settings->current()->sellTo;
        $countries = [];
        $states = [];
        foreach ($this->geography->countries() as $country) {
            if ($sellTo !== [] && !\in_array($country->code, $sellTo, true)) {
                continue;
            }
            $countries[] = $country;
            if ($country->hasStates) {
                $states[$country->code] = $this->geography->states($country->code);
            }
        }

        return ['countries' => $countries, 'states' => $states];
    }

    /** A country's name, or its code when the shop does not know it. */
    public function countryName(string $code): string
    {
        foreach ($this->geography->countries() as $country) {
            if ($country->code === $code) {
                return $country->name;
            }
        }

        return $code;
    }
}
