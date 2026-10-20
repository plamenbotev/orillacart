<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * The version of the extension API. It changes only by adding: a new capability, method on a value object or
 * optional argument. An add-on can check it before registering what needs a newer API:
 *
 *     if ($registry->version() >= 2) { ... }
 */
final class Version
{
    /**
     * 1: payment gateways, shipping methods. 2: price adjusters, cart rules, checkout fields, order actions,
     * orders. 3: gateways naming the payment of a notification (NamesNotifiedPayments), checking payments and
     * refunds at the provider (ChecksPayments, ChecksRefunds). 4: columns of the orders export and the products
     * file (OrderColumn, ProductColumn, ImportsProductColumn). 5: fees on orders (OrderFee), the handling of a
     * PaymentBreakdown, an order's fees in its OrderSummary, checking addresses (AddressCheck), each parcel's number
     * of a BookedShipment. 6: mailing lists for the newsletter's sign-ups (MailingList).
     */
    public const int CURRENT = 6;
}
