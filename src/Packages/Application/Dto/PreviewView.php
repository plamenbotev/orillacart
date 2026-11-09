<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/** What a preview rendered: the HTML, and the data the handler answered (null when it answered none). */
final readonly class PreviewView
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public string $html,
        public ?array $data,
    ) {}
}
