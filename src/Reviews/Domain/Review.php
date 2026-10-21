<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

/**
 * A review the site keeps, with the shop's answers to it.
 */
final readonly class Review
{
    /**
     * @param list<ReviewReply> $replies published, oldest first
     */
    public function __construct(
        public int $id,
        public int $productId,
        public int $rating,
        public string $text,
        public string $authorName,
        public bool $verified,
        public ReviewStatus $status,
        public \DateTimeImmutable $writtenAt,
        public array $replies = [],
    ) {}
}
