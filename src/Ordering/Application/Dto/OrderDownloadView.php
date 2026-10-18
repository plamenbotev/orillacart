<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\DownloadGrant;

/**
 * A file the buyer may download, with its link and what is left of it.
 */
final readonly class OrderDownloadView
{
    public function __construct(
        public int $orderNumber,
        public string $product,
        public string $name,
        public string $url,
        public ?int $remaining,
        public ?string $expiresAt,
        public int $downloads,
        public int $id = 0,
    ) {}

    /**
     * @param \Closure(string): string $url the link of a token
     */
    public static function fromGrant(DownloadGrant $grant, string $product, \Closure $url): self
    {
        return new self($grant->order()->id(), $product, $grant->name(), $url($grant->token()), $grant->remaining(), $grant->expiresAt()?->format(\DATE_ATOM), $grant->downloads(), $grant->id());
    }
}
