<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\AddressSuggestionsView;
use OrillaCart\Checkout\Application\Dto\AddressSuggestionView;
use OrillaCart\Checkout\Application\Dto\CheckAddresses;
use OrillaCart\Extension\Checkout\CheckedAddress;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * Asks the add-ons' address checks (AddressCheck) about the addresses a customer enters: the first with something
 * to say is heard. They suggest, never refuse: the customer takes the suggestion or keeps their address, and an
 * order placed with one a check would have changed says so to the shop. A check that fails is logged and left out.
 */
final readonly class AddressChecks
{
    public function __construct(
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
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
     * What the shop hears of an order placed with an address a check would have changed: a note each.
     *
     * @return list<string>
     */
    public function kept(AddressData $billing, ?AddressData $shippingAddress): array
    {
        $notes = [];
        foreach ([CheckedAddress::BILLING => $billing, CheckedAddress::SHIPPING => $shippingAddress] as $kind => $address) {
            $said = $address === null ? null : $this->about($address, $kind);
            if ($said !== null) {
                $notes[] = $said->found
                    ? \sprintf('The %s address check suggested %s; the customer kept their address.', $kind, implode(', ', array_filter([$said->street, $said->postcode, $said->city, $said->state], static fn(?string $part): bool => $part !== null && $part !== '')))
                    : \sprintf('The %s address check could not find the address (%s); the customer kept it.', $kind, $said->message);
            }
        }

        return $notes;
    }

    private function about(AddressData $address, string $kind): ?AddressSuggestionView
    {
        $checked = new CheckedAddress($kind, $address->street, $address->city, $address->postcode, $address->country, $address->state);
        foreach ($this->extensions->addressChecks() as $check) {
            try {
                $suggestion = $check->check($checked);
            } catch (\Throwable $e) {
                $this->logger->error('The address check {check} failed: {message}', ['check' => $check->id(), 'message' => $e->getMessage(), 'exception' => $e]);
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
