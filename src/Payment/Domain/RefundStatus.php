<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

enum RefundStatus: string
{
    /** Sent to the provider, which did not answer: trying again sends the same refund, never a second one. */
    case Pending = 'pending';
    /** The money went back, or the provider is sending it. */
    case Done = 'done';
    /** The provider refused it, or the shop gave up on a pending one. */
    case Failed = 'failed';
}
