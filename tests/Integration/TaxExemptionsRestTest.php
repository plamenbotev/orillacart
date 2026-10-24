<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Tax\Infrastructure\WordPress\OptionTaxSettingsRepository;

/**
 * Exemption certificates through the REST routes: the shop's own, and those a customer sends with a copy, which
 * wait for the shop to approve or refuse them.
 */
final class TaxExemptionsRestTest extends RestTestCase
{
    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n%%EOF\n";

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

    public function testACustomerSendsACertificateAndTheShopApprovesIt(): void
    {
        $customer = $this->actAs('subscriber');
        self::assertSame(['accepts' => false, 'exemptions' => []], $this->data($this->request('GET', '/account/tax-exemptions')));
        $fields = ['reason' => 'resale', 'certificate' => 'TX-778', 'regions' => ['US-TX'], 'expires_on' => '2030-12-31'];
        self::assertSame(422, $this->upload('/account/tax-exemptions', $this->pdf(), 'cert.pdf', $fields)->get_status(), 'the shop takes none yet');

        $this->actAs('administrator');
        $this->request('PUT', '/tax/settings', ['basis' => 'shipping_address', 'default_group_id' => null, 'accepts_certificates' => true], nonce: true);
        wp_set_current_user($customer);

        foreach ([
            'no states' => [['regions' => []] + $fields, self::PDF],
            'not a state' => [['regions' => ['Texas']] + $fields, self::PDF],
            'not a PDF' => [$fields, '<?php echo 1;'],
        ] as $case => [$sent, $content]) {
            self::assertSame(422, $this->upload('/account/tax-exemptions', $this->pdf($content), 'cert.pdf', $sent)->get_status(), $case);
        }
        self::assertSame(422, $this->upload('/account/tax-exemptions', $this->pdf(), 'cert.pdf', $fields, genuine: false)->get_status(), 'only files PHP received');

        $sent = $this->upload('/account/tax-exemptions', $this->pdf(), 'My certificate.pdf', $fields);
        self::assertSame(201, $sent->get_status(), (string) wp_json_encode($sent->get_data()));
        $id = $this->data($sent)['id'];
        self::assertIsInt($id);
        self::assertSame(['pending', 'TX-778', ['US-TX'], '2030-12-31'], [$this->data($sent)['status'], $this->data($sent)['certificate'], $this->data($sent)['regions'], $this->data($sent)['expires_on']]);
        self::assertArrayNotHasKey('note', $this->data($sent), 'the shop\'s note is its own');
        self::assertSame(403, $this->request('POST', "/customers/{$customer}/tax-exemptions/{$id}/approve", ['regions' => ['US-TX']], nonce: true)->get_status(), 'not by the customer');

        $this->actAs('administrator');
        $listed = $this->rows($this->data($this->request('GET', "/customers/{$customer}/tax-exemptions")));
        self::assertSame([['pending', 'My certificate.pdf']], array_map(static fn(array $exemption): array => [$exemption['status'], $exemption['file']], $listed));
        self::assertSame(422, $this->request('POST', "/customers/{$customer}/tax-exemptions/{$id}/refuse", ['reason' => ''], nonce: true)->get_status(), 'the customer is told why');
        $approved = $this->request('POST', "/customers/{$customer}/tax-exemptions/{$id}/approve", ['regions' => ['US-TX'], 'expires_on' => '2030-12-31'], nonce: true);
        self::assertSame(200, $approved->get_status(), (string) wp_json_encode($approved->get_data()));
        self::assertSame(422, $this->request('POST', "/customers/{$customer}/tax-exemptions/{$id}/refuse", ['reason' => 'No'], nonce: true)->get_status(), 'checked already');
        self::assertSame(422, $this->request('POST', '/customers/' . ($customer + 1000) . "/tax-exemptions/{$id}/approve", ['regions' => ['US-TX']], nonce: true)->get_status(), 'another customer\'s');
        self::assertContains('[' . get_bloginfo('name') . '] A tax exemption certificate to check', array_column($this->mails, 'subject'));

        wp_set_current_user($customer);
        self::assertSame('approved', $this->rows($this->data($this->request('GET', '/account/tax-exemptions'))['exemptions'])[0]['status']);

        $this->actAs('administrator');
        self::assertSame(204, $this->request('DELETE', "/customers/{$customer}/tax-exemptions/{$id}", nonce: true)->get_status());
    }

    public function testTheAccountListsTheCertificatesAndOffersTheForm(): void
    {
        $customer = $this->actAs('subscriber');
        $this->actAs('administrator');
        $this->request('PUT', '/tax/settings', ['basis' => 'shipping_address', 'default_group_id' => null, 'accepts_certificates' => true], nonce: true);
        wp_set_current_user($customer);
        self::assertSame(201, $this->upload('/account/tax-exemptions', $this->pdf(), 'cert.pdf', ['reason' => 'resale', 'certificate' => 'TX-778', 'regions' => ['US-TX']])->get_status());

        $_GET['section'] = 'tax-exemptions';
        try {
            $page = do_blocks('<!-- wp:orillacart/account /-->');
        } finally {
            unset($_GET['section']);
        }
        self::assertStringContainsString('TX-778', $page);
        self::assertStringContainsString('The shop is checking it', $page);
        self::assertStringContainsString('data-orillacart-tax-exemption', $page);
        self::assertStringContainsString('<option value="US-TX">Texas</option>', $page);
    }

    public function testOnlyCustomersReachTheirOwnAndShopManagersTheRest(): void
    {
        self::assertSame(401, $this->request('GET', '/account/tax-exemptions')->get_status());
        self::assertSame(401, $this->request('POST', '/account/tax-exemptions')->get_status());
        $this->actAs('editor');
        foreach ([['GET', '/customers/1/tax-exemptions'], ['POST', '/customers/1/tax-exemptions/1/approve'], ['POST', '/customers/1/tax-exemptions/1/refuse']] as [$method, $route]) {
            self::assertSame(403, $this->request($method, $route, nonce: true)->get_status(), "{$method} {$route}");
        }
    }

    /** @return non-empty-string */
    private function pdf(string $content = self::PDF): string
    {
        $path = tempnam(sys_get_temp_dir(), 'orillacart-test-certificate');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        self::assertIsList($value);

        /** @var list<array<string, mixed>> */
        return $value;
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_tax_exemptions");
        delete_option(OptionTaxSettingsRepository::OPTION);
    }
}
