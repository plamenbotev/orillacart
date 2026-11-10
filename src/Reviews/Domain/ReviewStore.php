<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

/**
 * Where reviews are kept: WordPress's comments, so the site's moderation, spam checks and privacy tools
 * look after them as after any comment.
 */
interface ReviewStore
{
    /**
     * Whether the product is published, asks the visitor for no password, and takes reviews (its discussion is
     * open).
     */
    public function isOpen(int $productId): bool;

    /**
     * Whether the product's reviews may be read: it is published and, where it has a password, the visitor gave
     * it.
     *
     * @param bool $byAnyone only a product without a password, whose reviews are everyone's to read
     */
    public function isReadable(int $productId, bool $byAnyone = false): bool;

    /** Whether the site lets only logged-in users write (Settings → Discussion). */
    public function loginRequired(): bool;

    /**
     * @throws ReviewRuleViolation when the site refuses it: written twice, or too soon after another
     */
    public function add(NewReview $review): Review;

    /** Whether the person reviewed the product already: published, waiting, or marked spam. */
    public function hasReviewed(int $productId, ?int $customerId, string $email): bool;

    /** Whether the email is an account's on the site: a guest does not review under it. */
    public function isAccountEmail(string $email): bool;

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
