<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

/**
 * Whether the product page offers the visitor to review the product, and as whom; or why not.
 */
final readonly class ReviewInvitationView
{
    public const string OFF = 'off';
    public const string CLOSED = 'closed';
    public const string LOG_IN = 'log_in';
    public const string BUYERS_ONLY = 'buyers_only';
    public const string REVIEWED = 'reviewed';

    public function __construct(
        public bool $open,
        /** Why not: off, closed, log_in, buyers_only or reviewed. */
        public ?string $reason = null,
        /** The name the review goes out under, when the shop knows the visitor; null asks for a name and email. */
        public ?string $author = null,
        public bool $verified = false,
    ) {}
}
