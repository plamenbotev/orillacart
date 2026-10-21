<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

/**
 * Where a review stands, as the site's discussion settings and spam checks left it.
 */
enum ReviewStatus: string
{
    case Published = 'published';
    /** Waiting for the shop to approve it. */
    case Held = 'held';
    case Spam = 'spam';
}
