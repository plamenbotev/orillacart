<?php

declare(strict_types=1);

/*
 * Fails when the built plugin does not work after prefixing: the autoloader must resolve prefixed library
 * classes and the plugin's own, and the libraries that generate or reflect code at runtime (Twig compiles
 * templates, the serializer, validator and Doctrine read attributes) must still find their prefixed classes.
 * Runs without WordPress. Usage: php bin/check-build.php <built-plugin-dir>
 */

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Serialization\ValidatorFactory;
use OrillaCart\Platform\Twig\TwigFactory;
use OrillaCart\Platform\Twig\TwigRenderer;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
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
echo "Doctrine reads the entity mapping and parses DQL\n";
