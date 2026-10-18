<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

enum ConsignmentStatus: string
{
    /** The courier booked it; it has not picked it up, or has not said so. */
    case Booked = 'booked';
    case OnTheWay = 'on_the_way';
    case Delivered = 'delivered';
    /** The courier is bringing it back. */
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /** Whether the courier still has something to say about it. */
    public function isOpen(): bool
    {
        return $this === self::Booked || $this === self::OnTheWay;
    }
}
