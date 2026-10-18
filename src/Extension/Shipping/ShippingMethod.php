<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Settings;

/**
 * A courier's own prices and services, such as DPD's. The shop makes a carrier that uses the method: the
 * carrier keeps its name and place at checkout, the method quotes the services that can ship each parcel.
 * Rates come from the method every time checkout prices an order; the shop keeps them for a few minutes, so a
 * customer filling in the form does not call the courier on every keystroke.
 */
interface ShippingMethod
{
    /** Stable, unique id, stored with carriers: "acme_courier". */
    public function id(): string;

    /** The method's name in the admin screen: "Acme Courier". */
    public function name(): string;

    /** @return list<Setting> */
    public function settings(): array;

    /** Whether the method can quote with these settings; checkout skips carriers whose method cannot. */
    public function isReady(Settings $settings): bool;

    /**
     * The services that can ship the parcel to its destination, each with its price before tax in the
     * shipment's currency. None when the courier does not serve it.
     *
     * @return list<Rate>
     *
     * @throws ProviderUnavailable when the courier cannot be asked; checkout then leaves the carrier out
     */
    public function quote(Shipment $shipment, Settings $settings): array;
}
