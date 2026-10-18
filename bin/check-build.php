<?php

declare(strict_types=1);

/*
 * Fails when the built plugin does not work after prefixing: the autoloader must resolve prefixed library
 * classes and the plugin's own, and the libraries that generate or reflect code at runtime (Twig compiles
 * templates, the serializer, validator and Doctrine read attributes) must still find their prefixed classes,
 * and every shipped template must compile to code that names only prefixed classes.
 * Runs without WordPress. Usage: php bin/check-build.php <built-plugin-dir>
 */

use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Ordering\Domain\DownloadGrant;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Serialization\ValidatorFactory;
use OrillaCart\Platform\Twig\ShopExtension;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Platform\Twig\TwigRenderer;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shipping\Domain\ShippingRate;
use OrillaCart\Tax\Domain\TaxGroup;

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
// Macros, imports and includes compile to code of their own, as do tests such as "in" and "starts with".
$templates = [
    'check.twig' => '{% import "macros.twig" as m %}{% for item in items %}{{ item|upper }} {% endfor %}{{ html }}'
        . '{{ "%.2f"|format(price) }}{{ m.mark("a") }}{% include "part.twig" with {x: 1} %}'
        . '{{ "a" in items ? "" : "-" }}{{ "mug" starts with "m" ? "!" : "" }}{{ items is empty ? "" : "." }}',
    'macros.twig' => '{% macro mark(text) %}[{{ text }}]{% endmacro %}',
    'part.twig' => '{% set y = x + 1 %}({{ y }})',
];
foreach ($templates as $name => $source) {
    file_put_contents($plugin . '/' . TwigFactory::PLUGIN_FOLDER . '/' . $name, $source);
}
$renderer = new TwigRenderer(new TwigFactory(new PluginContext($plugin . '/orillacart.php', 'check'))->create());
$html = $renderer->render('check.twig', ['items' => ['mug'], 'html' => '<b>', 'price' => 2.5]);
foreach (array_keys($templates) as $name) {
    unlink($plugin . '/' . TwigFactory::PLUGIN_FOLDER . '/' . $name);
}
rmdir($plugin . '/' . TwigFactory::PLUGIN_FOLDER);
rmdir($plugin);
if ($html !== 'MUG &lt;b&gt;2.50[a](2)-!.') {
    $fail('Twig rendered ' . var_export($html, true) . ' instead of the expected template output.');
}
echo "Twig compiles and renders templates\n";

// The shipped templates use more of Twig: compile each and look for a class name left without the prefix.
// The plugin's own extension names the functions and filters; compiling never calls them, so it needs no settings.
$extension = new ReflectionClass(ShopExtension::class)->newInstanceWithoutConstructor();
$shipped = new TwigFactory(new PluginContext($pluginDir . '/orillacart.php', 'check'), $extension)->create();
$folder = $pluginDir . '/' . TwigFactory::PLUGIN_FOLDER;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file instanceof SplFileInfo) {
        continue;
    }
    $name = substr($file->getPathname(), strlen($folder) + 1);
    $code = $shipped->compileSource($shipped->getLoader()->getSourceContext($name));
    // Twig's classes outside the library's prefix; the plugin's own Platform\Twig namespace is fine.
    if (preg_match('/(?<!Vendor\\\\)(?<!Platform\\\\)(?<![A-Za-z])Twig\\\\[A-Z]/', $code, $match) === 1) {
        $fail(sprintf('The compiled %s names %s without the prefix.', $name, $match[0]));
    }
}
echo "Every shipped template compiles to prefixed classes\n";

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
// Nested lists are typed by docblocks, read with phpdoc-parser, which was prefixed too.
$product = $mapper->map(['price' => '9.5', 'attributes' => [['name' => 'Size', 'options' => [['name' => 'M', 'adjustment' => '2']]]]], SaveProduct::class);
if (!($product->attributes[0] ?? null) instanceof SaveAttribute || $product->attributes[0]->options[0]->name !== 'M') {
    $fail('The serializer did not fill the nested attribute DTOs of a product.');
}
echo "Serializer and validator read DTO attributes\n";

// Doctrine reads the entity mapping from attributes, whose class names were prefixed with the library.
// With the server version given, Doctrine reads the mapping without connecting to a database.
$database = new DatabaseConfig('127.0.0.1', null, null, 'none', 'none', 'none', 'utf8mb4', '', 'wp_orillacart_', '8.4.0');
$entityManager = new EntityManagerFactory($database, new PluginContext($pluginDir . '/orillacart.php', 'check'))->create();
$metadata = $entityManager->getClassMetadata(TaxGroup::class);
if ($metadata->getTableName() !== 'wp_orillacart_tax_groups' || !$metadata->hasAssociation('rates') || !$metadata->isIdGeneratorIdentity()) {
    $fail('Doctrine did not read the mapping attributes of TaxGroup.');
}
$metadata = $entityManager->getClassMetadata(Product::class);
// Library classes cannot be named here: in the build they carry the prefix.
$joinTable = $metadata->getAssociationMapping('sets')->toArray()['joinTable'] ?? null;
if (!isset($metadata->embeddedClasses['pricing']) || !is_array($joinTable) || ($joinTable['name'] ?? null) !== 'wp_orillacart_product_attribute_sets') {
    $fail('Doctrine did not read the embedded pricing or the attribute sets of Product.');
}
// DQL names its token types in strings too; parsing a query proves the lexer finds them.
$sql = $entityManager->createQuery('SELECT g, r FROM ' . TaxGroup::class . ' g LEFT JOIN g.rates r ORDER BY g.name')->getSQL();
if (!is_string($sql) || !str_contains($sql, 'wp_orillacart_tax_groups')) {
    $fail('Doctrine could not turn a DQL query into SQL.');
}
// Embedded value objects with column prefixes, and enum columns, as orders and shipping rates use them.
$metadata = $entityManager->getClassMetadata(Order::class);
if (($metadata->getFieldMapping('billing.country')->columnName ?? null) !== 'billing_country' || ($metadata->getFieldMapping('status')->enumType ?? null) === null) {
    $fail('Doctrine did not read the embedded addresses or the status enum of Order.');
}
if ($entityManager->getClassMetadata(ShippingRate::class)->getFieldMapping('weight.max')->columnName !== 'weight_max') {
    $fail('Doctrine did not read the embedded ranges of ShippingRate.');
}
$sql = $entityManager->createQuery('DELETE FROM ' . Cart::class . ' c WHERE c.updatedAt < :before')->getSQL();
if (!is_string($sql) || !str_contains($sql, 'wp_orillacart_carts')) {
    $fail('Doctrine could not turn a DQL delete into SQL.');
}
// A download is counted with one UPDATE using CASE, whose DQL tokens must resolve in the scoped lexer too.
$sql = $entityManager->createQuery('UPDATE ' . DownloadGrant::class . ' g SET g.remaining = CASE WHEN g.remaining IS NULL THEN g.remaining ELSE g.remaining - 1 END WHERE g.id = :id')->getSQL();
if (!is_string($sql) || !str_contains($sql, 'CASE WHEN')) {
    $fail('Doctrine could not turn the download counter into SQL.');
}
echo "Doctrine reads the entity mapping and parses DQL\n";

// The extension API is what add-ons compile against: it may name only plain PHP and its own classes, since
// every library class is renamed in the build and an add-on cannot know the new names.
$extensionFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginDir . '/src/Extension', FilesystemIterator::SKIP_DOTS));
$checked = 0;
foreach ($extensionFiles as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $code = (string) file_get_contents($file->getPathname());
    if (preg_match('/OrillaCart\\\\{1,2}Vendor|\buse (?!OrillaCart\\\\Extension\\\\)[A-Z]\w*\\\\/', $code, $match) === 1) {
        $fail(sprintf('%s names %s: the extension API must not use library or plugin-internal classes.', $file->getFilename(), $match[0]));
    }
    ++$checked;
}
if ($checked === 0) {
    $fail('The build has no extension API.');
}
echo "The extension API names no library classes\n";
