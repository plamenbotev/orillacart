<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

/**
 * A test email: where it went, and whether the mail system took it.
 */
final readonly class TestEmailView
{
    public function __construct(
        public string $to,
        public bool $sent,
    ) {}
}
