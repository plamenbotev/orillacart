<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Payment\Application\Gateway\PaymentGateway;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;

/**
 * Every payment gateway the shop has, by id, in the order the admin screen first lists them.
 */
final readonly class Gateways
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    /**
     * @param iterable<PaymentGateway> $gateways
     */
    public function __construct(iterable $gateways)
    {
        $byId = [];
        foreach ($gateways as $gateway) {
            if (isset($byId[$gateway->id()])) {
                throw new \LogicException(\sprintf('Two payment gateways use the id "%s".', $gateway->id()));
            }
            $byId[$gateway->id()] = $gateway;
        }
        $this->gateways = $byId;
    }

    /** @return list<PaymentGateway> */
    public function all(): array
    {
        return array_values($this->gateways);
    }

    /**
     * @throws PaymentMethodNotFound
     */
    public function get(string $id): PaymentGateway
    {
        return $this->gateways[$id] ?? throw new PaymentMethodNotFound($id);
    }

    /** Its position when the shop never saved the method: as listed. */
    public function defaultPosition(string $id): int
    {
        $position = array_search($id, array_keys($this->gateways), true);

        return $position === false ? \count($this->gateways) : $position + 1;
    }
}
