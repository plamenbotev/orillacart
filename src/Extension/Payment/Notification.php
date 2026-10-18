<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * A call from the provider to the shop's notification address, as it arrived. Nothing in it is checked yet.
 */
final readonly class Notification
{
    /**
     * @param array<string, string> $headers by lowercase name
     * @param array<string, string> $query
     */
    public function __construct(
        public array $headers,
        public string $body,
        public array $query = [],
    ) {}

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
