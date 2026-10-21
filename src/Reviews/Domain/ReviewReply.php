<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

/**
 * An answer to a review, such as the shop's, written as a reply in WordPress's comments.
 */
final readonly class ReviewReply
{
    public function __construct(
        public string $authorName,
        public string $text,
        public \DateTimeImmutable $writtenAt,
    ) {}
}
