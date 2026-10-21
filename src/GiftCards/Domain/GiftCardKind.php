<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Domain;

enum GiftCardKind: string
{
    /** A code anyone who has it pays with. */
    case GiftCard = 'gift_card';
    /** A customer's own balance, used from their account; it has no code. */
    case StoreCredit = 'store_credit';
}
