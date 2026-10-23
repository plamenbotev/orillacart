<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Tax;

/**
 * A tax service working out the tax of the shop's sales, such as Avalara, TaxJar or Stripe Tax: it rates every
 * line (items, shipping, fees) by the address, the line's tax code and the customer's exemption, and records the
 * sales for the returns it files. The shop manager chooses it in Tax settings; the shop's own rates are used
 * otherwise, and when it cannot be reached, as the shop chose.
 *
 * Quotes are asked at checkout, often several times; commit, refund and void run in the background and are
 * tried again when they throw.
 */
interface TaxProvider
{
    /** Lowercase letters, digits and underscores, up to 40; unique among tax providers. */
    public function id(): string;

    /** As Tax settings offer it: "Avalara AvaTax". */
    public function label(): string;

    /**
     * The tax of every line of a cart or an order being edited.
     *
     * @throws \OrillaCart\Extension\ProviderUnavailable when the service cannot be reached or refuses
     */
    public function quote(TaxRequest $request): TaxResult;

    /**
     * The order was paid: record the sale, with the taxes it charged, for the returns.
     *
     * @return string the service's reference of the sale, kept with the order
     *
     * @throws \OrillaCart\Extension\ProviderUnavailable to be tried again later
     */
    public function commit(TaxedOrder $order): string;

    /**
     * Money was given back: report the tax in it, so the returns pay out less.
     *
     * @throws \OrillaCart\Extension\ProviderUnavailable to be tried again later
     */
    public function refund(TaxedOrder $order, TaxRefund $refund): void;

    /**
     * A recorded order was cancelled: void its sale.
     *
     * @throws \OrillaCart\Extension\ProviderUnavailable to be tried again later
     */
    public function void(TaxedOrder $order): void;
}
