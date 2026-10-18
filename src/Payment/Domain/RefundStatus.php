<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

enum RefundStatus: string
{
    /** Sent to the provider, which did not answer: trying again sends the same refund, never a second one. */
    case Pending = 'pending';
    /** The provider took it and sends the money later, as for an eCheck: the order learns of it once it is done. */
    case Accepted = 'accepted';
    /** The money went back. */
    case Done = 'done';
    /** The provider refused it or gave up on it, or the shop gave up on a pending one. */
    case Failed = 'failed';
}
