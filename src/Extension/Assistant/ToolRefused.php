<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Assistant;

/**
 * An add-on's tool would not do what it was asked: the message says what to correct, and the details (each field,
 * say) go with it. The model reads both. Since version 15.
 */
final class ToolRefused extends \RuntimeException
{
    /**
     * @param array<array-key, mixed> $details
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
