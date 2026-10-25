<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Invoicing\Infrastructure\WordPress\OptionInvoiceSettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Setup\Infrastructure\WordPress\OptionSetupAnswers;

/**
 * The setup through the REST API: answers kept as given, a plan in words that saves nothing, and applying it.
 */
final class SetupPlanTest extends RestTestCase
{
    private const array OPTIONS = [OptionSettingsRepository::OPTION, OptionInvoiceSettingsRepository::OPTION, OptionSetupAnswers::OPTION, OptionSetupAnswers::BEFORE_OPTION];

    /** @var array<string, mixed> */
    private array $kept = [];
    private ?\Closure $vies = null;
    /** @var list<string> */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::OPTIONS as $option) {
            $this->kept[$option] = get_option($option);
            delete_option($option);
        }
        // VIES answers an earlier run kept.
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_orillacart\\_vies\\_%' OR option_name LIKE '\\_transient\\_timeout\\_orillacart\\_vies\\_%'");
        wp_cache_flush();
        $this->vies = function (mixed $preempt, array $args, string $url): mixed {
            if (!str_contains($url, 'ec.europa.eu')) {
                return $preempt;
            }
            $this->asked[] = $url;

            $valid = str_ends_with($url, '/vat/123456789');

            return ['headers' => [], 'body' => (string) json_encode(['isValid' => $valid, 'userError' => $valid ? 'VALID' : 'INVALID', 'name' => 'ACME GMBH']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $this->vies, 10, 3);
        $this->actAs('administrator');
    }

    protected function tearDown(): void
    {
        if ($this->vies !== null) {
            remove_filter('pre_http_request', $this->vies);
        }
        foreach ($this->kept as $option => $value) {
            $value === false ? delete_option($option) : update_option($option, $value);
        }
        parent::tearDown();
    }

    public function testTheBusinessIsAskedOnceForTheSettingsAndTheInvoices(): void
    {
        $answers = $this->request('PUT', '/setup/answers/business', [
            'legal_name' => 'Acme GmbH', 'street' => 'Hauptstraße 1', 'city' => 'München', 'postcode' => '80331',
            'country' => 'DE', 'email' => 'shop@example.com', 'currency' => 'EUR', 'vat_number' => 'DE 123 456 789',
        ], nonce: true);
        self::assertSame(200, $answers->get_status());
        self::assertSame('Acme GmbH', self::answer($this->data($answers), 'legal_name'));

        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        $changes = self::changes($plan);
        self::assertSame(['business.location', 'business.seller'], array_column($changes, 'key'));
        self::assertSame('The shop sells from Germany. Prices are in EUR, written 10 €; weights in kg and sizes in cm.', $changes[0]['summary']);
        self::assertSame('Invoices name the seller as Acme GmbH, Hauptstraße 1, 80331 München, Germany, VAT number DE123456789.', $changes[1]['summary']);
        self::assertNull($changes[1]['warning'], 'the EU knows the number');
        self::assertFalse(get_option(OptionInvoiceSettingsRepository::OPTION), 'the plan saves nothing');
        self::assertNotSame([], $this->asked, 'VIES checked the number');

        $result = $this->data($this->request('POST', '/setup/apply', ['fingerprint' => $plan['fingerprint']], nonce: true));
        self::assertSame(['business.location', 'business.seller'], $result['applied']);
        $settings = $this->data($this->request('GET', '/settings'));
        self::assertSame(['DE', '80331', 'EUR', '€', 'after_space', 'kg'], [$settings['shop_country'], $settings['shop_postcode'], $settings['currency'], $settings['currency_sign'], $settings['currency_position'], $settings['weight_unit']]);
        $invoices = $this->data($this->request('GET', '/invoice-settings'));
        self::assertSame(['Acme GmbH', 'DE123456789', 'shop@example.com'], [$invoices['legal_name'], $invoices['vat_number'], $invoices['email']]);
        self::assertSame([], self::changes($this->data($this->request('POST', '/setup/plan', nonce: true))), 'nothing left to do');
    }

    public function testAVatNumberTheEuDoesNotKnowIsAWarningAndAnAnswerThatIsNotValidIsRefused(): void
    {
        $this->request('PUT', '/setup/answers/business', [
            'legal_name' => 'Acme GmbH', 'street' => 'Hauptstraße 1', 'city' => 'München', 'postcode' => '80331',
            'country' => 'DE', 'email' => 'shop@example.com', 'currency' => 'EUR', 'vat_number' => 'DE999999999',
        ], nonce: true);
        $changes = self::changes($this->data($this->request('POST', '/setup/plan', nonce: true)));
        self::assertSame('DE999999999 is not a valid VAT number: check it, or leave it out until you have one.', $changes[1]['warning']);

        $refused = $this->request('PUT', '/setup/answers/business', ['legal_name' => '', 'country' => 'DE'], nonce: true);
        self::assertSame(422, $refused->get_status());
        self::assertSame('DE999999999', self::answer($this->data($this->request('GET', '/setup/answers')), 'vat_number'), 'the answers before stay');

        self::assertSame(422, $this->request('POST', '/setup/apply', ['fingerprint' => str_repeat('0', 64)], nonce: true)->get_status(), 'a plan not seen');
    }

    /**
     * @param array<array-key, mixed> $plan
     *
     * @return list<array{key: string, summary: string, warning: ?string}>
     */
    private static function changes(array $plan): array
    {
        $changes = $plan['changes'] ?? null;
        self::assertIsList($changes);

        return array_map(static function (mixed $change): array {
            self::assertIsArray($change);
            self::assertIsString($change['key'] ?? null);
            self::assertIsString($change['summary'] ?? null);
            $warning = $change['warning'] ?? null;
            self::assertTrue($warning === null || \is_string($warning));

            return ['key' => $change['key'], 'summary' => $change['summary'], 'warning' => $warning];
        }, $changes);
    }

    /** @param array<array-key, mixed> $answers */
    private static function answer(array $answers, string $field): mixed
    {
        $business = $answers['business'] ?? null;
        self::assertIsArray($business);

        return $business[$field] ?? null;
    }
}
