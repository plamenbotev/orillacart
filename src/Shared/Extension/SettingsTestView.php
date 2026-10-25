<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

/**
 * What the provider said of the settings tried: they work, or what is wrong.
 */
final readonly class SettingsTestView
{
    public function __construct(
        public bool $works,
        public ?string $problem = null,
    ) {}
}
