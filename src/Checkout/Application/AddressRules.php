<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Geography\Domain\InvalidPostcode;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * What an order's address must be: in a known country, with a state exactly when the country has states, a
 * postcode that looks like the country's (kept in capitals with single spaces), and, when customers order, in a
 * country the shop sells to.
 */
final readonly class AddressRules
{
    public function __construct(
        private SettingsService $settings,
        private GeographyService $geography,
    ) {}

    /**
     * @param string $kind          "billing" or "shipping", for the messages
     * @param bool   $soldToCountry whether the shop must sell to the country; the shop's own orders may go anywhere
     *
     * @return AddressData the address, without a state where the country has none
     *
     * @throws CheckoutRuleViolation
     */
    public function require(AddressData $address, string $kind, bool $soldToCountry = true): AddressData
    {
        $sellTo = $this->settings->current()->sellTo;
        if ($soldToCountry && $sellTo !== [] && !\in_array($address->country, $sellTo, true)) {
            throw new CheckoutRuleViolation('The shop does not sell to the country of the %s address.', 'orillacart_country_not_served', values: [$kind]);
        }
        try {
            $states = $this->geography->states($address->country);
            $this->geography->require($address->country, $states === [] ? null : $address->state);
        } catch (RuleViolation) {
            throw new CheckoutRuleViolation('The %s address has an unknown country or state.', 'orillacart_invalid_address', values: [$kind]);
        }
        if ($states !== [] && $address->state === null) {
            throw new CheckoutRuleViolation('Choose the state of the %s address.', 'orillacart_invalid_address', values: [$kind]);
        }
        try {
            $postcode = $this->geography->postcode($address->country, $address->postcode);
        } catch (InvalidPostcode $e) {
            throw new CheckoutRuleViolation('The postcode of the %1$s address is not one of %2$s, which look like %3$s.', 'orillacart_invalid_postcode', $e, values: [$kind, $e->countryName, $e->example]);
        }

        return new AddressData($address->firstName, $address->lastName, $address->street, $address->city, $postcode, $address->country, $address->phone, $states === [] ? null : $address->state);
    }
}
