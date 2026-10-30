<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Exchange\Application\ProductImports;
use OrillaCart\Jobs\Application\JobRunner;

/**
 * A products file imported by its job on MySQL: a refused row rolls its transaction back without a second import
 * record or a batch run again, rows are written as the user who uploaded the file with their backslashes, SKUs
 * match as the database's unique index has them, and a file Excel saved in Windows-1252 reads right.
 */
final class ProductImportTest extends RestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testARefusedRowNeitherRepeatsTheBatchNorMakesASecondRecord(): void
    {
        $user = $this->actAs('administrator');
        $imports = self::kernel()->container()->get(ProductImports::class);
        self::assertInstanceOf(ProductImports::class, $imports);
        $file = tempnam(sys_get_temp_dir(), 'import');
        self::assertIsString($file);
        $description = '"C:\\lamps\\brass <iframe src=""https://example.com""></iframe>"';
        file_put_contents($file, mb_convert_encoding(implode("\n", [
            'sku,name,price,description',
            'IMP-LAMP,Café lamp,30,' . $description,
            'IMP-BAD,Broken,lots,',
            '',
            'imp-lamp,,32,' . $description,
            'IMP-BULB,Bulb,3,',
        ]) . "\n", 'Windows-1252', 'UTF-8'));
        $view = $imports->upload($file, 'products.csv', $user);
        $imports->start($view->id);
        wp_set_current_user(0);
        $runner = self::kernel()->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);
        for ($runs = 0; $runs < 5 && $runner->work(['imports'], 10, 'test')->count() > 0; ++$runs);

        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        self::assertSame('1', (string) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_product_imports"), 'one record, however the rows went');
        // Read from the table: this test's entity manager still holds the import as the start left it.
        $table = $wpdb->prefix . 'orillacart_product_imports';
        $done = $wpdb->get_row("SELECT status, done, created, updated, failed, errors FROM {$table} WHERE id = {$view->id}", ARRAY_A);
        self::assertIsArray($done);
        $cell = static fn(string $name): string => \is_scalar($done[$name] ?? null) ? (string) $done[$name] : '';
        self::assertSame(['done', '4', '2', '1', '1'], [$cell('status'), $cell('done'), $cell('created'), $cell('updated'), $cell('failed')], (string) wp_json_encode($done));
        self::assertSame(['Row 3: price: "lots" is not an amount such as 19.90.'], json_decode($cell('errors'), true));

        $lamps = get_posts(['post_type' => ProductPostType::NAME, 'title' => 'Café lamp', 'post_status' => 'any', 'numberposts' => -1]);
        self::assertCount(1, $lamps, 'the case of the SKU aside, the second row found the lamp');
        $lamp = $lamps[0];
        self::assertInstanceOf(\WP_Post::class, $lamp);
        self::assertSame(['publish', $user], [$lamp->post_status, (int) $lamp->post_author]);
        self::assertStringStartsWith('C:\\lamps\\brass', $lamp->post_content, 'the backslashes stay');
        if (user_can($user, 'unfiltered_html')) {
            self::assertStringContainsString('<iframe', $lamp->post_content, 'the HTML its user may use');
        }
        $products = self::kernel()->container()->get(ProductService::class);
        self::assertInstanceOf(ProductService::class, $products);
        self::assertSame(['32', 'imp-lamp'], [$products->product($lamp->ID)->price, $products->product($lamp->ID)->sku]);
        self::assertSame([], get_posts(['post_type' => ProductPostType::NAME, 'title' => 'Broken', 'post_status' => 'any', 'fields' => 'ids']), 'the refused row made nothing');
    }

    private function clean(): void
    {
        foreach (get_posts(['post_type' => ProductPostType::NAME, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => '']) as $left) {
            if (\in_array(get_the_title((int) $left), ['Café lamp', 'Broken', 'Bulb'], true)) {
                wp_delete_post((int) $left, true);
            }
        }
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_product_imports");
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_products WHERE sku IN ('IMP-LAMP', 'IMP-BULB')");
    }
}
