<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Platform\WordPress\Files\LocalPrivateFiles;

/**
 * The shop builds forms in the admin, and shoppers send files for their file fields.
 */
final class FormsRestTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];
    /** @var list<string> */
    private array $files = [];
    private ?\Closure $limits = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        // The guard's own limit is not what these tests check.
        $this->limits = static fn(array $limits): array => ['form_uploads' => null] + $limits;
        add_filter('orillacart_rate_limits', $this->limits, 20);
    }

    protected function tearDown(): void
    {
        if ($this->limits !== null) {
            remove_filter('orillacart_rate_limits', $this->limits, 20);
        }
        $this->clean();
        foreach ($this->posts as $id) {
            wp_delete_post($id, true);
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testOnlyShopManagersBuildForms(): void
    {
        self::assertSame(401, $this->request('GET', '/forms')->get_status());
        $this->actAs('subscriber');
        self::assertSame(403, $this->request('POST', '/forms', ['name' => 'Engraving'], nonce: true)->get_status());
    }

    public function testTheShopSavesChangesAndDeletesAForm(): void
    {
        $this->actAs('administrator');
        $created = $this->request('POST', '/forms', ['name' => 'Engraving', 'fields' => [
            ['label' => 'Engrave it', 'kind' => 'choice', 'key' => 'engrave', 'choices' => [['label' => 'Yes', 'value' => 'yes', 'price' => '5'], ['label' => 'No', 'value' => 'no']]],
            ['label' => 'Text', 'key' => 'text', 'required' => true, 'longest' => 20, 'condition' => ['field' => 'engrave', 'values' => ['yes']]],
            ['label' => 'Logo', 'kind' => 'file', 'types' => ['image/png'], 'largest' => 2, 'price' => '3'],
        ]], nonce: true);
        self::assertSame(201, $created->get_status(), (string) wp_json_encode($created->get_data()));
        $form = $this->data($created);
        self::assertSame(['engrave', 'text', 'logo'], array_column($this->rows($form['fields']), 'key'));

        $refused = $this->request('POST', '/forms', ['name' => 'At checkout', 'placement' => 'checkout', 'fields' => [['label' => 'Logo', 'kind' => 'file']]], nonce: true);
        self::assertSame([422, 'orillacart_invalid_form_field'], [$refused->get_status(), $this->data($refused)['code']]);
        $invalid = $this->request('POST', '/forms', ['name' => 'Bad', 'fields' => [['label' => 'Size', 'kind' => 'number', 'min' => '1e3']]], nonce: true);
        self::assertSame(422, $invalid->get_status());

        $id = $form['id'];
        self::assertIsInt($id);
        $updated = $this->request('PUT', "/forms/{$id}", ['name' => 'Rings', 'active' => false, 'scope' => 'categories', 'category_ids' => [7], 'fields' => []], nonce: true);
        self::assertSame(200, $updated->get_status(), (string) wp_json_encode($updated->get_data()));
        self::assertSame(['Rings', false, 'categories', [7]], [$this->data($updated)['name'], $this->data($updated)['active'], $this->data($updated)['scope'], $this->data($updated)['category_ids']]);

        self::assertSame(204, $this->request('DELETE', "/forms/{$id}", nonce: true)->get_status());
        self::assertSame(404, $this->request('GET', "/forms/{$id}")->get_status());
    }

    public function testAShopperSendsAPictureForAFileField(): void
    {
        [$product, $field] = $this->productWithFileField(['image/png', 'application/pdf']);
        wp_set_current_user(0);

        $png = $this->png() . '<?php echo "hidden"; ?>';
        $sent = $this->upload('/form-uploads', $this->file($png), '../../etc/lo‮gpj.png', ['product_id' => (string) $product, 'field' => $field]);
        self::assertSame(201, $sent->get_status(), (string) wp_json_encode($sent->get_data()));
        $upload = $this->data($sent);
        $token = $upload['token'] ?? null;
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame(['logpj.png', 'image/png'], [$upload['name'], $upload['type']]);

        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}orillacart_form_uploads", ARRAY_A);
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $token), $row['token_hash'], 'only the hash of the token is kept');
        $directory = get_option(LocalPrivateFiles::DIRECTORY_OPTION);
        self::assertIsString($directory);
        self::assertIsString($row['storage_key']);
        $kept = (string) file_get_contents($directory . '/' . $row['storage_key']);
        self::assertStringStartsWith("\x89PNG", $kept);
        self::assertStringNotContainsString('<?php', $kept, 'the picture was written again');
    }

    public function testUploadsThatDoNotHoldAreRefused(): void
    {
        [$product, $field] = $this->productWithFileField(['image/png']);
        wp_set_current_user(0);
        $fields = ['product_id' => (string) $product, 'field' => $field];

        $cases = [
            'a page called a picture' => [$this->upload('/form-uploads', $this->file('<html><script>alert(1)</script></html>'), 'cat.png', $fields), 'This field takes PNG files.'],
            'a PDF the field does not take' => [$this->upload('/form-uploads', $this->file("%PDF-1.4\n%%EOF"), 'a.png', $fields), 'This field takes PNG files.'],
            'a broken picture' => [$this->upload('/form-uploads', $this->file(substr($this->png(), 0, 33) . str_repeat("\0", 64)), 'a.png', $fields), 'This picture cannot be read.'],
            'too large' => [$this->upload('/form-uploads', $this->file($this->png() . str_repeat('x', 1024 * 1024 + 1)), 'a.png', $fields), 'The file is larger than 1 MB.'],
            'another field' => [$this->upload('/form-uploads', $this->file($this->png()), 'a.png', ['product_id' => (string) $product, 'field' => 'f1_nothing']), 'This product asks for no such file.'],
            'a server file' => [$this->upload('/form-uploads', '/etc/hostname', 'a.png', $fields, genuine: false), 'Choose one file, up to 20 MB.'],
        ];
        foreach ($cases as $case => [$response, $message]) {
            self::assertSame([422, [['field' => 'file', 'message' => $message]]], [$response->get_status(), $this->violations($response)], $case);
        }
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        self::assertSame('0', $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_form_uploads"));
    }

    public function testAPdfThatRunsAnythingIsRefused(): void
    {
        [$product, $field] = $this->productWithFileField(['application/pdf']);
        wp_set_current_user(0);

        $response = $this->upload('/form-uploads', $this->file("%PDF-1.4\n1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n%%EOF"), 'a.pdf', ['product_id' => (string) $product, 'field' => $field]);
        self::assertSame(422, $response->get_status());
        self::assertSame('This PDF has scripts, actions, attachments, forms or encryption; send one without.', $this->violations($response)[0]['message'] ?? null);
    }

    /**
     * @param list<string> $types
     *
     * @return array{int, string} the product and the field id
     */
    private function productWithFileField(array $types): array
    {
        $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Mug', 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '10', 'stock_mode' => 'always_available'], nonce: true)->get_status());
        $form = $this->data($this->request('POST', '/forms', ['name' => 'Print', 'scope' => 'products', 'product_ids' => [$id], 'fields' => [
            ['label' => 'Logo', 'kind' => 'file', 'key' => 'logo', 'types' => $types, 'largest' => 1],
        ]], nonce: true));

        self::assertIsInt($form['id'] ?? null);

        return [$id, "f{$form['id']}_logo"];
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(8, 8);
        self::assertNotFalse($image);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @return non-empty-string
     */
    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'form-upload');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> */
        return array_values($value);
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_forms");
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_form_uploads");
    }
}
