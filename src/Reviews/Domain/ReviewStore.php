<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

/**
 * Where reviews are kept: WordPress's comments, so the site's moderation, spam checks and privacy tools
 * look after them as after any comment.
 */
interface ReviewStore
{
    /** Whether the product is published and takes reviews (its discussion is open). */
    public function isOpen(int $productId): bool;

    /** Whether the site lets only logged-in users write (Settings → Discussion). */
    public function loginRequired(): bool;

    /**
     * @throws ReviewRuleViolation when the site refuses it: written twice, or too soon after another
     */
    public function add(NewReview $review): Review;

    /** Whether the person reviewed the product already, published or waiting. */
    public function hasReviewed(int $productId, ?int $customerId, string $email): bool;

    /**
     * The product's published reviews, newest first.
     *
     * @return array{list<Review>, int} a page of them and how many there are
     */
    public function published(int $productId, int $offset, int $limit): array;

    /**
     * @param list<int> $productIds
     *
     * @return array<int, Rating> by product id; products without published reviews are left out
     */
    public function ratings(array $productIds): array;
}
