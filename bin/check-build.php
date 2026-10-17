<?php

declare(strict_types=1);

/*
 * Fails when the built plugin does not work after prefixing: the autoloader must resolve prefixed library
 * classes and the plugin's own, and the libraries that generate or reflect code at runtime (Twig compiles
 * templates, the serializer and validator read attributes) must still find their prefixed classes.
 * Runs without WordPress. Usage: php bin/check-build.php <built-plugin-dir>
 */

use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Serialization\ValidatorFactory;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Platform\Twig\TwigRenderer;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;

$pluginDir = $argv[1] ?? '';
if ($pluginDir === '' || !is_file($pluginDir . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php bin/check-build.php <built-plugin-dir>\n");
    exit(1);
}

require $pluginDir . '/vendor/autoload.php';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$classes = [
    'OrillaCart\\Vendor\\Symfony\\Component\\DependencyInjection\\Container',
    'OrillaCart\\Vendor\\Doctrine\\ORM\\EntityManager',
    'OrillaCart\\Vendor\\Doctrine\\Migrations\\DependencyFactory',
    'OrillaCart\\Vendor\\Twig\\Environment',
    'OrillaCart\\Platform\\Kernel',
];
foreach ($classes as $class) {
    if (!class_exists($class)) {
        $fail("The build's autoloader cannot find {$class}");
    }
}
echo "Autoloader resolves library and plugin classes\n";

// Twig writes PHP for each template that names its own classes; they must be the prefixed ones.
// Rendered through the plugin's own factory, from a template in a temporary plugin folder.
$plugin = sys_get_temp_dir() . '/orillacart-check-build-' . bin2hex(random_bytes(4));
mkdir($plugin . '/' . TwigFactory::PLUGIN_FOLDER, 0o755, true);
file_put_contents(
    $plugin . '/' . TwigFactory::PLUGIN_FOLDER . '/check.twig',
    '{% for item in items %}{{ item|upper }} {% endfor %}{{ html }}{{ "%.2f"|format(price) }}',
);
$renderer = new TwigRenderer(new TwigFactory(new PluginContext($plugin . '/orillacart.php', 'check'))->create());
$html = $renderer->render('check.twig', ['items' => ['mug'], 'html' => '<b>', 'price' => 2.5]);
unlink($plugin . '/' . TwigFactory::PLUGIN_FOLDER . '/check.twig');
rmdir($plugin . '/' . TwigFactory::PLUGIN_FOLDER);
rmdir($plugin);
if ($html !== 'MUG &lt;b&gt;2.50') {
    $fail('Twig rendered ' . var_export($html, true) . ' instead of the expected template output.');
}
echo "Twig compiles and renders templates\n";

// The serializer and validator read attributes from plugin DTOs; their class names were rewritten too.
$serializers = new SerializerFactory();
$mapper = new RequestMapper($serializers->create(), new ValidatorFactory()->create(), $serializers->nameConverter());
$dto = $mapper->map(['currency' => 'USD', 'price_decimals' => 2, 'weight_unit' => 'lb', 'dimension_unit' => 'in'], UpdateSettings::class);
if ($dto->currency !== 'USD' || $dto->priceDecimals !== 2) {
    $fail('The serializer did not fill the settings DTO.');
}
try {
    $mapper->map(['currency' => 'usd', 'price_decimals' => 2, 'weight_unit' => 'lb', 'dimension_unit' => 'in'], UpdateSettings::class);
    $fail('The validator accepted a lower-case currency code.');
} catch (HttpException $e) {
    if ($e->status !== 422) {
        $fail('Invalid input gave status ' . $e->status . ' instead of 422.');
    }
}
echo "Serializer and validator read DTO attributes\n";
