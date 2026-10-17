<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

enum PaymentStatus: string
{
    /** Started: an offline payment the shop waits for, or a provider payment the customer has not finished. */
    case Pending = 'pending';
    /** The money is the shop's. */
    case Completed = 'completed';
    /** The provider declined or failed it. */
    case Failed = 'failed';
    /** The customer gave up before paying. */
    case Cancelled = 'cancelled';
}
