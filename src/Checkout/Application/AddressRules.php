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
     * @param 'billing'|'shipping' $kind which address, for the messages
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
            throw new CheckoutRuleViolation($kind === 'billing' ? 'The shop does not sell to the country of the billing address.' : 'The shop does not sell to the country of the shipping address.', 'orillacart_country_not_served');
        }
        try {
            $states = $this->geography->states($address->country);
            $this->geography->require($address->country, $states === [] ? null : $address->state);
        } catch (RuleViolation) {
            throw new CheckoutRuleViolation($kind === 'billing' ? 'The billing address has an unknown country or state.' : 'The shipping address has an unknown country or state.', 'orillacart_invalid_address');
        }
        if ($states !== [] && $address->state === null) {
            throw new CheckoutRuleViolation($kind === 'billing' ? 'Choose the state of the billing address.' : 'Choose the state of the shipping address.', 'orillacart_invalid_address');
        }
        try {
            $postcode = $this->geography->postcode($address->country, $address->postcode);
        } catch (InvalidPostcode $e) {
            throw new CheckoutRuleViolation(
                $kind === 'billing' ? 'The postcode of the billing address is not one of %1$s, which look like %2$s.' : 'The postcode of the shipping address is not one of %1$s, which look like %2$s.',
                'orillacart_invalid_postcode',
                $e,
                values: [$e->countryName, $e->example],
            );
        }

        return new AddressData($address->firstName, $address->lastName, $address->street, $address->city, $postcode, $address->country, $address->phone, $states === [] ? null : $address->state);
    }
}
