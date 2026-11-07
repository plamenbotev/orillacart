<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Scripting;

use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Scripting\Application\ScriptStore;

/**
 * Compiled classes as PHP files under the shop's cache folder (`scripts/<build>/<key>.php`), which OPcache keeps
 * in memory; a new build gets a new folder and the old one is cleared, as Twig's is. Without a writable folder
 * the source is evaluated in memory, once per request.
 */
final readonly class CompiledScriptStore implements ScriptStore
{
    public function __construct(private PluginContext $context) {}

    public function load(string $key, string $className): bool
    {
        $path = $this->path($key);
        if ($path === null || !is_file($path)) {
            return false;
        }
        require_once $path;

        return class_exists($className, false);
    }

    public function put(string $key, string $className, string $source): void
    {
        $path = $this->path($key);
        if ($path !== null) {
            $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($temporary, $source) !== false && rename($temporary, $path)) {
                require_once $path;
                if (class_exists($className, false)) {
                    return;
                }
            }
            @unlink($temporary);
        }
        // The class is defined in memory; the generated code is what the compiler made and nothing else.
        eval(substr($source, \strlen('<?php')));
    }

    public function forget(string $keyPrefix): void
    {
        $folder = CacheFolders::path('scripts', $this->context->build);
        if ($folder === null) {
            return;
        }
        $files = glob($folder . '/' . self::safe($keyPrefix) . '*.php');
        foreach ($files === false ? [] : $files as $file) {
            @unlink($file);
        }
    }

    private function path(string $key): ?string
    {
        $folder = CacheFolders::path('scripts', $this->context->build);

        return $folder === null ? null : $folder . '/' . self::safe($key) . '.php';
    }

    private static function safe(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $key) ?? $key;
    }
}
