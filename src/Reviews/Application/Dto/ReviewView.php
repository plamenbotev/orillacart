<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

use OrillaCart\Reviews\Domain\Review;
use OrillaCart\Reviews\Domain\ReviewReply;

final readonly class ReviewView
{
    /**
     * @param list<ReviewReplyView> $replies
     */
    public function __construct(
        public int $id,
        public int $rating,
        public string $text,
        public string $author,
        /** The author paid for the product. */
        public bool $verified,
        public string $status,
        public string $writtenAt,
        public array $replies,
    ) {}

    public static function of(Review $review): self
    {
        return new self(
            $review->id,
            $review->rating,
            $review->text,
            $review->authorName,
            $review->verified,
            $review->status->value,
            $review->writtenAt->format(\DATE_ATOM),
            array_map(static fn(ReviewReply $reply): ReviewReplyView => new ReviewReplyView($reply->authorName, $reply->text, $reply->writtenAt->format(\DATE_ATOM)), $review->replies),
        );
    }
}
