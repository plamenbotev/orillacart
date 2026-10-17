<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Application;

use OrillaCart\Geography\Application\Dto\CountryView;
use OrillaCart\Geography\Application\Dto\LocationView;
use OrillaCart\Geography\Application\Dto\StateView;
use OrillaCart\Geography\Domain\CountryRepository;
use OrillaCart\Geography\Domain\UnknownCountry;
use OrillaCart\Geography\Domain\UnknownState;
use OrillaCart\Shared\Domain\NotFound;

/**
 * Countries and states for address forms, and the checks other areas run on the locations they store.
 */
final readonly class GeographyService
{
    public function __construct(private CountryRepository $countries) {}

    /**
     * @return list<CountryView> by name
     */
    public function countries(): array
    {
        return array_map(CountryView::fromCountry(...), $this->countries->all());
    }

    /**
     * @return list<StateView> by name; empty for countries whose addresses have no state line
     */
    public function states(string $country): array
    {
        $found = $this->countries->find($country)
            ?? throw new NotFound(\sprintf('There is no country with the code "%s".', $country), 'orillacart_country_not_found');

        return array_map(StateView::fromState(...), $found->states());
    }

    /**
     * Where an address is, as far as it is known: null without a known country, and no state unless the
     * state belongs to the country. For addresses customers typed.
     */
    public function locate(?string $country, ?string $state): ?LocationView
    {
        $found = $country === null || $country === '' ? null : $this->countries->find($country);
        if ($found === null) {
            return null;
        }

        return new LocationView($found->code, $state === null ? null : $found->state($state)?->code, $found->inEu);
    }

    /**
     * The same location, refused when the country is unknown or the state is not one of its states.
     * For locations the shop configures.
     *
     * @throws UnknownCountry
     * @throws UnknownState
     */
    public function require(string $country, ?string $state): LocationView
    {
        $found = $this->countries->find($country) ?? throw new UnknownCountry($country);
        if ($state !== null && $found->state($state) === null) {
            throw new UnknownState($country, $state);
        }

        return new LocationView($found->code, $state, $found->inEu);
    }
}
