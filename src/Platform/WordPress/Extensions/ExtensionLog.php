<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Extensions;

use OrillaCart\Extension\Log;
use Psr\Log\LoggerInterface;

/**
 * The shop's logger, as add-ons see it: without the library types the release build renames.
 */
final readonly class ExtensionLog implements Log
{
    public function __construct(private LoggerInterface $logger) {}

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}
