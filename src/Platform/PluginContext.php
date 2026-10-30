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

    /**
     * The version and the newest migration of these files, "2.0.0+20261024082543": what upgrades and the compiled
     * caches go by, so files rebuilt under the same version (a fixed release, a development build) still bring
     * their schema and drop the mapping cached for the old one.
     */
    public string $build;

    public function __construct(
        public string $pluginFile,
        public string $version,
    ) {
        $this->dir = \dirname($pluginFile);
        $this->build = self::buildOf($this->dir, $version);
    }

    /**
     * The build of the files on disk now, read again from the plugin's header and migrations: another than $build
     * once the plugin was updated under a process that started before, such as a worker that runs for hours.
     */
    public function filesBuild(): string
    {
        clearstatcache();
        $head = @file_get_contents($this->pluginFile, false, null, 0, 8192);
        $version = \is_string($head) && preg_match('/^[ \t\/*#@]*Version:(.*)$/mi', $head, $match) === 1 ? trim($match[1]) : $this->version;

        return self::buildOf($this->dir, $version);
    }

    private static function buildOf(string $dir, string $version): string
    {
        $migrations = glob($dir . '/migrations/Version*.php');
        $migrations = $migrations === false ? [] : $migrations;

        return $migrations === [] ? $version : $version . '+' . substr(basename((string) max($migrations), '.php'), \strlen('Version'));
    }

    public function path(string $relative): string
    {
        return $this->dir . '/' . ltrim($relative, '/');
    }
}
