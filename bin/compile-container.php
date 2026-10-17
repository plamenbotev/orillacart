<?php

declare(strict_types=1);

/*
 * Compiles the service container for a release: writes generated/CompiledContainer.php and
 * generated/container.php (which returns an instance) into the given plugin directory.
 * Usage: php bin/compile-container.php <plugin-dir>
 */

use OrillaCart\Platform\Kernel;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

$pluginDir = $argv[1] ?? '';
if ($pluginDir === '' || !is_dir($pluginDir)) {
    fwrite(STDERR, "Usage: php bin/compile-container.php <plugin-dir>\n");
    exit(1);
}
$pluginDir = (string) realpath($pluginDir);

require $pluginDir . '/vendor/autoload.php';

$builder = Kernel::buildContainer($pluginDir);
$builder->compile();

$code = new PhpDumper($builder)->dump([
    'namespace' => 'OrillaCart\\Generated',
    'class' => 'CompiledContainer',
]);
if (!is_string($code)) {
    fwrite(STDERR, "The container dumped into several files; expected one.\n");
    exit(1);
}

$generated = $pluginDir . '/generated';
if (!is_dir($generated) && !mkdir($generated, 0o755, true)) {
    fwrite(STDERR, "Cannot create {$generated}\n");
    exit(1);
}

file_put_contents($generated . '/CompiledContainer.php', $code);
file_put_contents(
    $pluginDir . '/' . Kernel::COMPILED_CONTAINER_FILE,
    "<?php\n\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/CompiledContainer.php';\n\nreturn new \\OrillaCart\\Generated\\CompiledContainer();\n",
);

echo "Compiled container written to {$generated}\n";
