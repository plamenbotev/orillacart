<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

/**
 * An email as it would go: to whom, its subject, and its body as HTML and plain text.
 */
final readonly class EmailPreviewView
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $html,
        public string $text,
    ) {}
}
