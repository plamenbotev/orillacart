<?php

declare(strict_types=1);

/*
 * Fails when the built plugin's autoloader cannot resolve prefixed library classes or the plugin's own.
 * Usage: php bin/check-build.php <built-plugin-dir>
 */

$pluginDir = $argv[1] ?? '';
if ($pluginDir === '' || !is_file($pluginDir . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php bin/check-build.php <built-plugin-dir>\n");
    exit(1);
}

require $pluginDir . '/vendor/autoload.php';

$classes = [
    'OrillaCart\\Vendor\\Symfony\\Component\\DependencyInjection\\Container',
    'OrillaCart\\Vendor\\Doctrine\\ORM\\EntityManager',
    'OrillaCart\\Vendor\\Doctrine\\Migrations\\DependencyFactory',
    'OrillaCart\\Platform\\Kernel',
];

foreach ($classes as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "The build's autoloader cannot find {$class}\n");
        exit(1);
    }
}

echo "Autoloader resolves library and plugin classes\n";
