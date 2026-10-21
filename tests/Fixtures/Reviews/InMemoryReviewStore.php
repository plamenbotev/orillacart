<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Reviews;

use OrillaCart\Reviews\Domain\NewReview;
use OrillaCart\Reviews\Domain\Rating;
use OrillaCart\Reviews\Domain\Review;
use OrillaCart\Reviews\Domain\ReviewStatus;
use OrillaCart\Reviews\Domain\ReviewStore;

/**
 * Reviews as the comments would keep them: new ones published unless $hold, products open unless closed.
 */
final class InMemoryReviewStore implements ReviewStore
{
    /** @var list<array{Review, NewReview}> */
    public array $reviews = [];
    /** @var list<int> */
    public array $closed = [];
    public bool $hold = false;
    public bool $loginRequired = false;

    public function isOpen(int $productId): bool
    {
        return !\in_array($productId, $this->closed, true);
    }

    public function loginRequired(): bool
    {
        return $this->loginRequired;
    }

    public function add(NewReview $review): Review
    {
        $saved = new Review(\count($this->reviews) + 1, $review->productId, $review->rating, $review->text, $review->authorName, $review->verified, $this->hold ? ReviewStatus::Held : ReviewStatus::Published, new \DateTimeImmutable('2026-10-17 12:00:00 UTC'));
        $this->reviews[] = [$saved, $review];

        return $saved;
    }

    public function hasReviewed(int $productId, ?int $customerId, string $email): bool
    {
        foreach ($this->reviews as [$saved, $review]) {
            if ($review->productId === $productId && (($customerId !== null && $review->customerId === $customerId) || strcasecmp($review->authorEmail, $email) === 0)) {
                return true;
            }
        }

        return false;
    }

    public function published(int $productId, int $offset, int $limit): array
    {
        $published = array_values(array_filter(array_reverse(array_column($this->reviews, 0)), static fn(Review $review): bool => $review->productId === $productId && $review->status === ReviewStatus::Published));

        return [\array_slice($published, $offset, $limit), \count($published)];
    }

    public function ratings(array $productIds): array
    {
        $stars = [];
        foreach (array_column($this->reviews, 0) as $review) {
            \assert($review instanceof Review);
            if ($review->status === ReviewStatus::Published && \in_array($review->productId, $productIds, true)) {
                $stars[$review->productId][$review->rating] = ($stars[$review->productId][$review->rating] ?? 0) + 1;
            }
        }

        return array_map(static fn(array $counts): Rating => new Rating($counts), $stars);
    }
}
