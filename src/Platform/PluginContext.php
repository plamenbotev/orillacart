<?php

declare(strict_types=1);

namespace OrillaCart\Platform;

/**
 * Where and which version of the plugin is running. Set at runtime, never compiled into the container,
 * because the container is compiled on the build machine.
 */
final readonly class PluginContext
{
    public string $dir;

    public function __construct(
        public string $pluginFile,
        public string $version,
    ) {
        $this->dir = \dirname($pluginFile);
    }

    public function path(string $relative): string
    {
        return $this->dir . '/' . ltrim($relative, '/');
    }
}
