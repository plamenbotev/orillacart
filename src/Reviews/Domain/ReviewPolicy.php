<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

/**
 * Who may review the shop's products. Reviews from those who bought the product carry a mark either way.
 */
enum ReviewPolicy: string
{
    /** Products show no reviews and take none. */
    case Off = 'off';
    /** Whoever the site's discussion settings let comment. */
    case Anyone = 'anyone';
    /** Only those who paid for the product, by account or through their receipt. */
    case Buyers = 'buyers';
}
