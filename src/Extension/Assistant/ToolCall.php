<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Assistant;

/**
 * Who called an add-on's tool. Since version 15.
 */
final readonly class ToolCall
{
    /**
     * @param int         $userId  the shop manager the assistant acts as
     * @param string|null $keyName the API key it came with, by name
     */
    public function __construct(
        public int $userId,
        public ?string $keyName,
    ) {}
}
