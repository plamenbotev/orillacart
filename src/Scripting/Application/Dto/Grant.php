<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Dto;

/**
 * What one run is allowed and knows about the extension it runs for: the permissions a manager granted, the
 * hosts it may call, its settings' values, and its id for the log.
 */
final readonly class Grant
{
    /**
     * @param list<string>               $permissions `orders:read`, `http`, …
     * @param list<string>               $hosts       the hosts `http` may call
     * @param array<string, string|bool> $settings    the extension's own settings
     */
    public function __construct(
        public string $extension = '',
        public array $permissions = [],
        public array $hosts = [],
        public array $settings = [],
    ) {}

    public function allows(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }
}
