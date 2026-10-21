<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

final readonly class ReviewReplyView
{
    public function __construct(
        public string $author,
        public string $text,
        public string $writtenAt,
    ) {}
}
