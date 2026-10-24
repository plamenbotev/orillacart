<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Domain;

enum ReturnStatus: string
{
    /** The customer asked; the shop has not answered. */
    case Requested = 'requested';
    /** The shop agreed: the customer sends the items back. */
    case Approved = 'approved';
    case Refused = 'refused';
    /** The items came back to the shop. */
    case Received = 'received';
    /** The refund was made and the provider sends the money later: refunded once it says it did. */
    case Refunding = 'refunding';
    /** The money for them went back. */
    case Refunded = 'refunded';

    /** Whether its items are still on their way back: they cannot be asked for again. */
    public function holdsItems(): bool
    {
        return \in_array($this, [self::Requested, self::Approved, self::Received, self::Refunding], true);
    }
}
