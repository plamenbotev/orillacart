<?php

declare(strict_types=1);

/*
 * Compiles the service container for a release: writes generated/CompiledContainer.php and
 * generated/container.php (which returns an instance) into the given plugin directory.
 * Usage: php bin/compile-container.php <plugin-dir>
 */

use OrillaCart\Platform\Kernel;

$pluginDir = $argv[1] ?? '';
if ($pluginDir === '' || !is_dir($pluginDir)) {
    fwrite(STDERR, "Usage: php bin/compile-container.php <plugin-dir>\n");
    exit(1);
}
$pluginDir = (string) realpath($pluginDir);

require $pluginDir . '/vendor/autoload.php';

$builder = Kernel::buildContainer($pluginDir);
$builder->compile();

$generated = $pluginDir . '/' . dirname(Kernel::COMPILED_CONTAINER_FILE);
Kernel::writeContainer($builder, $generated, 'CompiledContainer');

echo "Compiled container written to {$generated}\n";
