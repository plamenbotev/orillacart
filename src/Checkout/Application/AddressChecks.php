<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\AddressSuggestionsView;
use OrillaCart\Checkout\Application\Dto\AddressSuggestionView;
use OrillaCart\Checkout\Application\Dto\CheckAddresses;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Extension\Checkout\CheckedAddress;
use OrillaCart\Extension\FailsClosed;
use OrillaCart\Shared\Extension\ClosedFailures;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * Asks the add-ons' address checks (AddressCheck) about the addresses a customer enters: the first with something
 * to say is heard. They suggest, never refuse: the customer takes the suggestion or keeps their address, and an
 * order placed with one a check would have changed says so to the shop. A check that fails is logged and left out;
 * one the shop cannot do without (FailsClosed) says the address cannot be checked right now, and the order waits
 * until it can.
 */
final readonly class AddressChecks
{
    /** What the customer is told when a check the shop cannot do without failed. */
    public const string UNCHECKED = 'The address cannot be checked right now. Try again in a moment.';

    public function __construct(
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
        private ClosedFailures $closedFailures,
    ) {}

    /** Whether any add-on checks addresses, so checkout asks before placing the order. */
    public function any(): bool
    {
        return $this->extensions->addressChecks() !== [];
    }

    public function suggest(CheckAddresses $query): AddressSuggestionsView
    {
        return new AddressSuggestionsView(
            $this->about($query->billing, CheckedAddress::BILLING),
            $query->shippingAddress === null ? null : $this->about($query->shippingAddress, CheckedAddress::SHIPPING),
        );
    }

    /**
     * What the shop hears of an order placed with an address a check would have changed: a note each. Asked
     * before the order is placed.
     *
     * @return list<string>
     *
     * @throws CheckoutRuleViolation when a check that fails closed failed
     */
    public function kept(AddressData $billing, ?AddressData $shippingAddress): array
    {
        $notes = [];
        foreach ([CheckedAddress::BILLING => $billing, CheckedAddress::SHIPPING => $shippingAddress] as $kind => $address) {
            $unchecked = false;
            $said = $address === null ? null : $this->about($address, $kind, $unchecked);
            if ($unchecked) {
                throw new CheckoutRuleViolation(self::UNCHECKED, 'orillacart_address_unchecked');
            }
            if ($said !== null) {
                $notes[] = $said->found
                    ? \sprintf('The %s address check suggested %s; the customer kept their address.', $kind, implode(', ', array_filter([$said->street, $said->postcode, $said->city, $said->state], static fn(?string $part): bool => $part !== null && $part !== '')))
                    : \sprintf('The %s address check could not find the address (%s); the customer kept it.', $kind, $said->message);
            }
        }

        return $notes;
    }

    /** @param bool $unchecked set when a check that fails closed failed */
    private function about(AddressData $address, string $kind, bool &$unchecked = false): ?AddressSuggestionView
    {
        $checked = new CheckedAddress($kind, $address->street, $address->city, $address->postcode, $address->country, $address->state);
        foreach ($this->extensions->addressChecks() as $check) {
            try {
                $suggestion = $check->check($checked);
            } catch (\Throwable $e) {
                $this->logger->error('The address check {check} failed: {message}', ['check' => $check->id(), 'message' => $e->getMessage(), 'exception' => $e]);
                if ($check instanceof FailsClosed) {
                    $unchecked = true;
                    $this->closedFailures->failed('address_check:' . $check->id(), \sprintf('The address check %s failed (%s): no order can be placed until it answers.', $check->id(), mb_substr($e->getMessage(), 0, 200)));

                    return new AddressSuggestionView(self::UNCHECKED, false);
                }
                continue;
            }
            if ($suggestion === null) {
                continue;
            }
            if (!$suggestion->isCorrection()) {
                return new AddressSuggestionView(mb_substr($suggestion->message, 0, 300), false);
            }
            $corrected = [$suggestion->street ?? $address->street, $suggestion->city ?? $address->city, $suggestion->postcode ?? $address->postcode, $suggestion->state ?? $address->state];
            if ($corrected === [$address->street, $address->city, $address->postcode, $address->state]) {
                // A "correction" that changes nothing says the address is fine.
                return null;
            }

            return new AddressSuggestionView(mb_substr($suggestion->message, 0, 300), true, ...array_map(static fn(?string $part): ?string => $part === null ? null : mb_substr(trim($part), 0, 200), $corrected));
        }

        return null;
    }
}
