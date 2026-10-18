<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;
use OrillaCart\Shared\Extension\InstalledExtensions;

/**
 * Every payment gateway the shop has, its own and the add-ons', by id, in the order the admin screen first
 * lists them.
 */
final readonly class Gateways
{
    public function __construct(private InstalledExtensions $extensions) {}

    /** @return list<PaymentGateway> */
    public function all(): array
    {
        return $this->extensions->paymentGateways();
    }

    /**
     * @throws PaymentMethodNotFound
     */
    public function get(string $id): PaymentGateway
    {
        foreach ($this->all() as $gateway) {
            if ($gateway->id() === $id) {
                return $gateway;
            }
        }

        throw new PaymentMethodNotFound($id);
    }

    /** Its position when the shop never saved the method: as listed. */
    public function defaultPosition(string $id): int
    {
        $ids = array_map(static fn(PaymentGateway $gateway): string => $gateway->id(), $this->all());
        $position = array_search($id, $ids, true);

        return $position === false ? \count($ids) : $position + 1;
    }
}
