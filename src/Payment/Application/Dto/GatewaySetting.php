<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * One setting a gateway needs, as the admin screen shows it. Secrets are never sent back to the screen.
 */
final readonly class GatewaySetting
{
    public const string TEXT = 'text';
    public const string SECRET = 'secret';
    public const string BOOLEAN = 'boolean';

    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TEXT,
        public bool $required = false,
        public string $help = '',
    ) {}
}
