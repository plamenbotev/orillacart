<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

enum RefundMethod: string
{
    /** Through the gateway that took the payment. */
    case Provider = 'provider';
    /** Made outside the shop, such as a bank transfer back, and recorded here. */
    case Manual = 'manual';
    /** Made at the provider's own site; the shop learned of it from a notification. */
    case External = 'external';
    /** Given as store credit, or for a guest as a gift card, to spend in the shop. */
    case StoreCredit = 'store_credit';
}
