<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shipping\Application\Dto\ShippingMethodView;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;

/**
 * The shipping methods add-ons registered, which carriers can be priced with.
 */
final readonly class ShippingMethods
{
    public function __construct(private InstalledExtensions $extensions) {}

    /**
     * @return list<ShippingMethodView> as registered
     */
    public function methods(): array
    {
        return array_map(static fn(ShippingMethod $method): ShippingMethodView => new ShippingMethodView($method->id(), $method->name(), $method->settings()), $this->extensions->shippingMethods());
    }

    /** Null when no add-on registers the method, as when it was turned off. */
    public function find(string $id): ?ShippingMethod
    {
        foreach ($this->extensions->shippingMethods() as $method) {
            if ($method->id() === $id) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @throws ShippingRuleViolation
     */
    public function get(string $id): ShippingMethod
    {
        return $this->find($id) ?? throw new ShippingRuleViolation(\sprintf('There is no shipping method "%s"; is its add-on active?', $id), 'orillacart_unknown_shipping_method');
    }
}
