<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

/**
 * A review as it is written: the stars, the words, and who wrote it.
 */
final readonly class NewReview
{
    public const int MAX_TEXT_LENGTH = 5000;
    public const int MAX_NAME_LENGTH = 100;

    /**
     * @param bool $verified the author paid for the product
     *
     * @throws ReviewRuleViolation
     */
    public function __construct(
        public int $productId,
        public int $rating,
        public string $text,
        public string $authorName,
        public string $authorEmail,
        public ?int $customerId,
        public bool $verified,
    ) {
        if ($rating < 1 || $rating > 5) {
            throw new ReviewRuleViolation('A review gives 1 to 5 stars.', 'orillacart_invalid_rating');
        }
        if (trim($text) === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new ReviewRuleViolation(\sprintf('A review says something, in at most %d characters.', self::MAX_TEXT_LENGTH), 'orillacart_invalid_review');
        }
        if (trim($authorName) === '' || mb_strlen($authorName) > self::MAX_NAME_LENGTH || filter_var($authorEmail, \FILTER_VALIDATE_EMAIL) === false) {
            throw new ReviewRuleViolation('A review needs the name and email of who wrote it.', 'orillacart_invalid_review_author');
        }
    }
}
