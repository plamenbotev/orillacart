<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Files;

/**
 * Whether the web server sent a test file when PHP handed it over, and the server setting it needs if not.
 */
final readonly class DeliveryCheck
{
    /**
     * @param string $serverRule what to add to the web server's configuration for the method to work
     */
    public function __construct(
        public bool $works,
        public string $message,
        public string $serverRule,
    ) {}
}
