<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * Which address decides the taxes of a sale.
 */
enum TaxBasis: string
{
    /** The customer's shipping address; the shop's while it is not known, as in the cart. */
    case ShippingAddress = 'shipping_address';
    /** The customer's billing address; the shop's while it is not known. */
    case BillingAddress = 'billing_address';
    /** The shop's own address, whoever buys. */
    case ShopAddress = 'shop_address';
    /**
     * The shop's address for customers shipping inside the EU, or whose address is not known yet;
     * no tax for customers shipping outside the EU.
     */
    case EuOrigin = 'eu_origin';
    /**
     * The customer's own EU country, as the One-Stop-Shop (OSS) asks of shops selling across the EU: their
     * shipping address inside the EU, no tax outside it, and the shop's address while the address is not known.
     */
    case EuDestination = 'eu_destination';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The location whose rates apply, or null when no tax applies. An address not known yet is null: the shop's
     * own address stands in for it, so a price is never shown without the tax it will carry.
     */
    public function location(?TaxLocation $shipping, ?TaxLocation $billing, ?TaxLocation $shop): ?TaxLocation
    {
        return match ($this) {
            self::ShippingAddress => $shipping ?? $shop,
            self::BillingAddress => $billing ?? $shop,
            self::ShopAddress => $shop,
            self::EuOrigin => $shipping === null || $shipping->inEu ? $shop : null,
            self::EuDestination => $shipping === null ? $shop : ($shipping->inEu ? $shipping : null),
        };
    }
}
