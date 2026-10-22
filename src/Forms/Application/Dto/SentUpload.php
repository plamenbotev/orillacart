<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

/**
 * The upload as the shopper names it in the answer: by the token only they were given.
 */
final readonly class SentUpload
{
    public function __construct(
        public string $token,
        public string $name,
        public string $type,
        public int $size,
    ) {}
}
