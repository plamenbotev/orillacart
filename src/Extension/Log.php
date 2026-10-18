<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * The shop's log. Context values replace {placeholders} in the message; never log secrets or card data.
 */
interface Log
{
    /** @param array<string, scalar|null> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, scalar|null> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string, scalar|null> $context */
    public function error(string $message, array $context = []): void;
}
