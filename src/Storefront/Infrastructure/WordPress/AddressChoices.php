<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Geography\Application\Dto\CountryView;
use OrillaCart\Geography\Application\Dto\StateView;
use OrillaCart\Geography\Application\GeographyService;

/**
 * The countries and states address forms offer, and country names for showing addresses.
 */
final readonly class AddressChoices
{
    public function __construct(private GeographyService $geography) {}

    /**
     * @return array{countries: list<CountryView>, states: array<string, list<StateView>>, country_names: array<string, string>}
     */
    public function all(): array
    {
        $countries = $this->geography->countries();
        $states = [];
        $names = [];
        foreach ($countries as $country) {
            $names[$country->code] = $country->name;
            if ($country->hasStates) {
                $states[$country->code] = $this->geography->states($country->code);
            }
        }

        return ['countries' => $countries, 'states' => $states, 'country_names' => $names];
    }
}
