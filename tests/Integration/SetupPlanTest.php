<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Invoicing\Infrastructure\WordPress\OptionInvoiceSettingsRepository;
use OrillaCart\Notifications\Infrastructure\WordPress\OptionEmailSettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSetupState;
use OrillaCart\Setup\Infrastructure\WordPress\OptionSetupAnswers;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionLegalSettingsRepository;
use OrillaCart\Storefront\Infrastructure\WordPress\OptionOpeningRepository;
use OrillaCart\Tax\Infrastructure\WordPress\OptionTaxSettingsRepository;

/**
 * The setup through the REST API: answers kept as given, a plan in words that saves nothing, and applying it.
 */
final class SetupPlanTest extends RestTestCase
{
    private const array OPTIONS = [OptionSettingsRepository::OPTION, OptionInvoiceSettingsRepository::OPTION, OptionTaxSettingsRepository::OPTION, OptionLegalSettingsRepository::OPTION, OptionEmailSettingsRepository::OPTION, OptionOpeningRepository::OPTION, OptionSetupState::OPTION, OptionSetupAnswers::OPTION, OptionSetupAnswers::BEFORE_OPTION];

    /** @var array<string, mixed> */
    private array $kept = [];
    private ?\Closure $vies = null;
    /** @var list<string> */
    private array $asked = [];
    /** @var list<int> the tax groups there were before the test */
    private array $groups = [];
    /** @var list<int> the carriers there were before the test */
    private array $carriers = [];
    /** @var list<int> the pages there were before the test */
    private array $pages = [];
    /** @var list<array<array-key, mixed>> the payment methods as they were */
    private array $methods = [];

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
        $this->groups = array_column(self::rows($this->data($this->request('GET', '/tax/groups'))), 'id');
        $this->carriers = array_column(self::rows($this->data($this->request('GET', '/carriers'))), 'id');
        $this->pages = self::pageIds();
        $this->methods = array_values(array_filter($this->data($this->request('GET', '/payment-methods')), \is_array(...)));
    }

    protected function tearDown(): void
    {
        $this->actAs('administrator');
        if ($this->vies !== null) {
            remove_filter('pre_http_request', $this->vies);
        }
        foreach ($this->kept as $option => $value) {
            $value === false ? delete_option($option) : update_option($option, $value);
        }
        $this->request('DELETE', '/sample-data', nonce: true);
        foreach (array_diff(self::pageIds(), $this->pages) as $page) {
            wp_delete_post($page, true);
        }
        foreach ($this->methods as $method) {
            self::assertIsString($method['gateway'] ?? null);
            $this->request('PUT', '/payment-methods/' . $method['gateway'], [
                'name' => $method['name'], 'active' => $method['active'], 'position' => $method['position'], 'countries' => $method['countries'],
                'instructions' => $method['instructions'], 'receipt_note' => $method['receipt_note'], 'fee' => $method['fee'], 'fee_percent' => $method['fee_percent'], 'fee_label' => $method['fee_label'],
            ], nonce: true);
        }
        foreach (self::rows($this->data($this->request('GET', '/carriers'))) as $carrier) {
            if (!\in_array($carrier['id'], $this->carriers, true)) {
                $this->request('DELETE', '/carriers/' . $carrier['id'], nonce: true);
            }
        }
        foreach (self::rows($this->data($this->request('GET', '/tax/groups'))) as $group) {
            if (!\in_array($group['id'], $this->groups, true)) {
                $this->request('DELETE', '/tax/groups/' . $group['id'], nonce: true);
            }
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
        self::assertSame('The shop sells from Germany. Prices are in EUR, written 10 €; weights in kilograms and sizes in centimetres.', $changes[0]['summary']);
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

    public function testAGermanShopForConsumersChargesGermanVatIncludedInItsPrices(): void
    {
        $this->business('DE', 'EUR');
        $this->request('PUT', '/setup/answers/selling', ['customers' => 'both', 'regions' => 'eu'], nonce: true);
        $this->request('PUT', '/setup/answers/tax', ['registered' => true], nonce: true);

        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        $changes = self::changes($plan);
        self::assertSame(['business.location', 'business.seller', 'selling.countries', 'tax.group.standard', 'tax.group.reduced', 'tax.group.zero', 'tax.settings'], array_column($changes, 'key'));
        self::assertSame('The shop sells to consumers and businesses in 27 countries.', $changes[2]['summary']);
        self::assertSame('A tax group "Standard rate" at 19% (MwSt.).', $changes[3]['summary']);
        self::assertSame('A tax group "Reduced rate" at 7% (MwSt.).', $changes[4]['summary']);

        $result = $this->data($this->request('POST', '/setup/apply', ['fingerprint' => $plan['fingerprint']], nonce: true));
        self::assertNull($result['refused']);
        $tax = $this->data($this->request('GET', '/tax/settings'));
        self::assertSame(['eu_origin', true, true, 'with_tax', 'incl. MwSt., plus shipping', true], [$tax['basis'], $tax['prices_include_tax'], $tax['reverse_charge'], $tax['shown_in_shop'], $tax['price_suffix'], $tax['shipping_taxed_as_items']]);
        $standard = array_values(array_filter(self::rows($this->data($this->request('GET', '/tax/groups'))), static fn(array $group): bool => $group['name'] === 'Standard rate'));
        self::assertSame($standard[0]['id'] ?? null, $tax['default_group_id']);
        $sellTo = $this->data($this->request('GET', '/settings'))['sell_to'] ?? null;
        self::assertIsArray($sellTo);
        self::assertCount(27, $sellTo);
        self::assertSame([], self::changes($this->data($this->request('POST', '/setup/plan', nonce: true))), 'set up');
    }

    public function testInTheOneStopShopEveryEuCountryHasItsOwnRates(): void
    {
        $this->business('FR', 'EUR');
        $this->request('PUT', '/setup/answers/tax', ['registered' => true, 'oss' => true], nonce: true);

        $changes = self::changes($this->data($this->request('POST', '/setup/plan', nonce: true)));
        $keys = array_column($changes, 'key');
        self::assertSame(['tax.group.standard', 'tax.group.reduced', 'tax.group.second_reduced', 'tax.group.super_reduced', 'tax.group.zero'], array_values(array_filter($keys, static fn(string $key): bool => str_starts_with($key, 'tax.group.'))));
        self::assertSame('A tax group "Standard rate": each EU country\'s own rate, 27 rates.', $changes[2]['summary']);
        self::assertSame('Reduced rates cover different goods from one EU country to the next: check which group each product belongs to.', self::change($changes, 'tax.settings')['warning']);
    }

    public function testAUsShopChargesTheStateRatesWhereItHasNexusAndSaysLocalOnesAreMissing(): void
    {
        $this->business('US', 'USD', 'CA');
        $this->request('PUT', '/setup/answers/selling', ['customers' => 'consumers', 'regions' => 'home'], nonce: true);
        $this->request('PUT', '/setup/answers/tax', ['registered' => true, 'regions' => ['CA', 'TX']], nonce: true);

        $changes = self::changes($this->data($this->request('POST', '/setup/plan', nonce: true)));
        self::assertSame('A tax group "Standard rate": CA Sales tax 7.25%, TX Sales tax 6.25%.', self::change($changes, 'tax.group.standard')['summary']);
        self::assertSame('Most states add local sales taxes to their own rate. A tax service charges them, or enter them in Tax by hand.', self::change($changes, 'tax.settings')['warning']);
        self::assertSame('The shop sells from United States. Prices are in USD, written $10; weights in pounds and sizes in inches.', $changes[0]['summary']);
    }

    public function testASmallBusinessChargesNoVatAndItsInvoicesSayWhy(): void
    {
        $this->business('DE', 'EUR');
        $this->request('PUT', '/setup/answers/tax', ['registered' => false], nonce: true);

        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        $changes = self::changes($plan);
        self::assertSame(['business.location', 'business.seller', 'tax.settings', 'tax.note'], array_column($changes, 'key'));
        self::assertSame('The shop charges no tax (MwSt.).', $changes[2]['summary']);
        self::assertSame('Invoices say: "Gemäß § 19 UStG wird keine Umsatzsteuer berechnet."', $changes[3]['summary']);

        $this->request('POST', '/setup/apply', ['fingerprint' => $plan['fingerprint']], nonce: true);
        $footer = $this->data($this->request('GET', '/invoice-settings'))['footer'] ?? null;
        self::assertIsString($footer);
        self::assertStringContainsString('§ 19 UStG', $footer);
        self::assertSame('shop_address', $this->data($this->request('GET', '/tax/settings'))['basis']);
    }

    public function testShippingIsPricedForEachZoneTheShopSellsTo(): void
    {
        $this->business('DE', 'EUR');
        $this->request('PUT', '/setup/answers/selling', ['customers' => 'consumers', 'regions' => 'world'], nonce: true);
        $this->request('PUT', '/setup/answers/shipping', [
            'name' => 'Standard shipping',
            'zones' => [
                ['zone' => 'home', 'prices' => 'free_above', 'price' => '4.90', 'free_above' => '50'],
                ['zone' => 'eu', 'prices' => 'weight', 'weights' => [['up_to' => '2', 'price' => '9.90'], ['up_to' => '10', 'price' => '19.90']]],
                ['zone' => 'world', 'prices' => 'none'],
            ],
        ], nonce: true);

        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        $carrier = self::change(self::changes($plan), 'shipping.carrier');
        self::assertSame('Checkout offers "Standard shipping":', $carrier['summary']);
        self::assertSame('Nothing ships to the rest of the world, so customers there cannot order: give it a price, or sell only where the shop ships.', $carrier['warning']);

        $this->request('PUT', '/setup/answers/shipping', [
            'name' => 'Standard shipping',
            'zones' => [
                ['zone' => 'home', 'prices' => 'free_above', 'price' => '4.90', 'free_above' => '50'],
                ['zone' => 'eu', 'prices' => 'weight', 'weights' => [['up_to' => '2', 'price' => '9.90'], ['up_to' => '10', 'price' => '19.90']]],
                ['zone' => 'world', 'prices' => 'flat', 'price' => '29'],
            ],
            'pickup' => ['name' => 'Our shop', 'address' => 'Hauptstraße 1, München', 'hours' => 'Mon–Fri 9–18'],
        ], nonce: true);
        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        self::assertNull(self::change(self::changes($plan), 'shipping.carrier')['warning']);
        $result = $this->data($this->request('POST', '/setup/apply', ['fingerprint' => $plan['fingerprint']], nonce: true));
        self::assertNull($result['refused']);

        $made = array_values(array_filter(self::rows($this->data($this->request('GET', '/carriers'))), fn(array $carrier): bool => !\in_array($carrier['id'], $this->carriers, true)));
        $names = array_column($made, 'id', 'name');
        ksort($names);
        self::assertSame(['Our shop', 'Standard shipping'], array_keys($names));
        $standard = $this->data($this->request('GET', '/carriers/' . $names['Standard shipping']));
        $rates = $standard['rates'] ?? null;
        self::assertIsArray($rates);
        self::assertCount(5, $rates, 'two at home, two in the EU by weight, one for the world');
        self::assertSame([], array_values(array_filter(array_column(self::changes($this->data($this->request('POST', '/setup/plan', nonce: true))), 'key'), static fn(string $key): bool => str_starts_with($key, 'shipping.'))), 'shipped as planned');
    }

    public function testCustomersPayByBankTransferIntoTheShopsAccountOrCashAtHome(): void
    {
        $this->business('DE', 'EUR');
        $this->request('PUT', '/setup/answers/payments', [
            'bank_transfer' => true, 'cash_on_delivery' => true, 'cheque' => false,
            'bank' => ['holder' => 'Acme', 'iban' => 'de89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX'],
        ], nonce: true);

        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        $changes = self::changes($plan);
        self::assertSame('Customers can pay by bank transfer to Acme.', self::change($changes, 'payments.bank_transfer')['summary']);
        self::assertNull(self::change($changes, 'payments.bank_transfer')['warning'], 'a valid IBAN');
        self::assertSame('Invoices give the bank account: Acme, IBAN DE89 3704 0044 0532 0130 00, BIC COBADEFFXXX.', self::change($changes, 'payments.bank_details')['summary']);

        $result = $this->data($this->request('POST', '/setup/apply', ['fingerprint' => $plan['fingerprint']], nonce: true));
        self::assertNull($result['refused']);
        $methods = array_column(array_filter($this->data($this->request('GET', '/payment-methods')), \is_array(...)), null, 'gateway');
        $bank = $methods['bank_transfer'] ?? [];
        self::assertTrue($bank['active'] ?? null);
        self::assertSame('Pay to Acme, IBAN DE89 3704 0044 0532 0130 00, BIC COBADEFFXXX, and give your order number as the reference.', $bank['instructions'] ?? null);
        $cash = $methods['cash_on_delivery'] ?? [];
        self::assertSame([true, ['DE']], [$cash['active'] ?? null, $cash['countries'] ?? null]);
        self::assertSame([], array_values(array_filter(array_column(self::changes($this->data($this->request('POST', '/setup/plan', nonce: true))), 'key'), static fn(string $key): bool => str_starts_with($key, 'payments.'))), 'paid as planned');
    }

    public function testAnIbanThatDoesNotAddUpIsAWarning(): void
    {
        $this->request('PUT', '/setup/answers/payments', ['bank_transfer' => true, 'bank' => ['holder' => 'Acme', 'iban' => 'DE89 3704 0044 0532 0130 01']], nonce: true);

        self::assertSame('DE89 3704 0044 0532 0130 01 is not a valid IBAN: check it.', self::change(self::changes($this->data($this->request('POST', '/setup/plan', nonce: true))), 'payments.bank_transfer')['warning']);
    }

    public function testPayPalsKeysAreTriedAtPayPal(): void
    {
        $paypal = static function (mixed $preempt, array $args, string $url): mixed {
            if (!str_contains($url, 'paypal.com/v1/oauth2/token')) {
                return $preempt;
            }

            return ['headers' => [], 'body' => '{"error":"invalid_client"}', 'response' => ['code' => 401, 'message' => 'Unauthorized'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $paypal, 10, 3);
        try {
            $tried = $this->data($this->request('POST', '/payment-methods/paypal/test', ['settings' => ['client_id' => 'abc', 'client_secret' => 'nope', 'sandbox' => true]], nonce: true));
        } finally {
            remove_filter('pre_http_request', $paypal);
        }

        self::assertSame([false, 'PayPal refused the credentials (401); check the client ID and secret.'], [$tried['works'], $tried['problem']]);
    }

    public function testAGermanShopGetsItsLegalPagesAndCheckoutAsksForTheTerms(): void
    {
        $this->business('DE', 'EUR');
        $this->request('PUT', '/setup/answers/legal', [], nonce: true);

        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        $changes = self::changes($plan);
        self::assertSame('A draft page "Terms of sale" for you to check and publish.', self::change($changes, 'legal.page.terms')['summary']);
        self::assertSame('A page "Impressum", kept up to date from the shop\'s settings.', self::change($changes, 'legal.page.legal_notice')['summary']);
        self::assertSame('Checkout asks customers to accept the terms of sale, and its button says "Buy now".', self::change($changes, 'legal.checkout')['summary']);

        $result = $this->data($this->request('POST', '/setup/apply', ['fingerprint' => $plan['fingerprint']], nonce: true));
        self::assertNull($result['refused']);
        $legal = $this->data($this->request('GET', '/legal-settings'));
        self::assertSame([true, 'buy_now'], [$legal['accept_terms'], $legal['order_button']]);
        self::assertIsInt($legal['terms']);
        self::assertIsInt($legal['withdrawal']);
        self::assertSame('draft', get_post_status($legal['terms']), 'for the owner to check');
        self::assertStringContainsString('You have the right to withdraw from this contract within 14 days', (string) get_post_field('post_content', $legal['withdrawal']));
        self::assertStringContainsString('Acme', (string) get_post_field('post_content', $legal['withdrawal']), 'the seller to write to');
        self::assertIsInt($legal['legal_notice']);
        self::assertSame('publish', get_post_status($legal['legal_notice']));
        self::assertStringContainsString('<strong>Acme</strong>', do_blocks((string) get_post_field('post_content', $legal['legal_notice'])), 'the seller as invoices name them');
        $settings = $this->data($this->request('GET', '/settings'));
        self::assertSame([14, true], [$settings['return_days'], $settings['show_lowest_price']]);
        self::assertSame([], array_values(array_filter(array_column(self::changes($this->data($this->request('POST', '/setup/plan', nonce: true))), 'key'), static fn(string $key): bool => str_starts_with($key, 'legal.'))), 'set up');
    }

    public function testEmailsFromAnotherDomainThanTheSitesAreAWarning(): void
    {
        $this->request('PUT', '/setup/answers/emails', ['from_name' => 'Acme', 'from_address' => 'shop@example.com'], nonce: true);

        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        $sender = self::change(self::changes($plan), 'emails.sender');
        self::assertSame('Emails come from "Acme" <shop@example.com>; new orders go to shop@example.com.', $sender['summary']);
        self::assertStringStartsWith('Mail from shop@example.com may land in spam: the site is ', (string) $sender['warning']);

        $this->request('POST', '/setup/apply', ['fingerprint' => $plan['fingerprint']], nonce: true);
        $emails = $this->data($this->request('GET', '/email-settings'));
        self::assertSame(['Acme', 'shop@example.com'], [$emails['from_name'], $emails['from_address']]);
    }

    public function testAGermanShopIsReadyOnceTheWholeSetupIsAppliedAndItsDraftsPublished(): void
    {
        $this->request('DELETE', '/sample-data', nonce: true);
        $before = $this->data($this->request('GET', '/setup/readiness'));
        self::assertFalse($before['ready']);

        $this->business('DE', 'EUR');
        $this->request('PUT', '/setup/answers/selling', ['customers' => 'both', 'regions' => 'eu'], nonce: true);
        $this->request('PUT', '/setup/answers/tax', ['registered' => true], nonce: true);
        $this->request('PUT', '/setup/answers/shipping', ['name' => 'Standard shipping', 'zones' => [
            ['zone' => 'home', 'prices' => 'flat', 'price' => '4.90'],
            ['zone' => 'eu', 'prices' => 'flat', 'price' => '12.90'],
        ]], nonce: true);
        $this->request('PUT', '/setup/answers/payments', ['bank_transfer' => true, 'bank' => ['holder' => 'Acme', 'iban' => 'DE89370400440532013000']], nonce: true);
        $this->request('PUT', '/setup/answers/legal', [], nonce: true);
        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        $result = $this->data($this->request('POST', '/setup/apply', ['fingerprint' => $plan['fingerprint']], nonce: true));
        self::assertNull($result['refused']);

        $stops = array_column(self::rowsOf($this->data($this->request('GET', '/setup/readiness'))['stops'] ?? []), 'key');
        self::assertSame(['legal.terms', 'legal.withdrawal', 'products.none'], self::sorted($stops), 'the drafts to check, and something to sell');

        $legal = $this->data($this->request('GET', '/legal-settings'));
        foreach (['terms', 'withdrawal'] as $part) {
            self::assertIsInt($legal[$part]);
            wp_update_post(['ID' => $legal[$part], 'post_status' => 'publish']);
        }
        $this->request('POST', '/sample-data', nonce: true);

        $after = $this->data($this->request('GET', '/setup/readiness'));
        self::assertSame([], array_column(self::rowsOf($after['stops'] ?? []), 'key'));
        self::assertTrue($after['ready']);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function rowsOf(mixed $rows): array
    {
        self::assertIsList($rows);

        return array_values(array_filter($rows, \is_array(...)));
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    public function testTheShopOpensSoonUntilItsManagerOpensItAndVisitorsCannotOrderMeanwhile(): void
    {
        $this->request('PUT', '/setup/answers/opening', ['opens_soon' => true], nonce: true);
        $plan = $this->data($this->request('POST', '/setup/plan', nonce: true));
        self::assertSame(['opening.soon'], array_column(self::changes($plan), 'key'));
        $this->request('POST', '/setup/apply', ['fingerprint' => $plan['fingerprint']], nonce: true);
        self::assertTrue($this->data($this->request('GET', '/opening'))['opens_soon']);

        wp_set_current_user(0);
        $refused = $this->request('POST', '/checkout', ['email' => 'ada@example.com', 'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000']], nonce: true);
        self::assertSame('orillacart_opening_soon', $this->data($refused)['code'] ?? null);

        $this->actAs('administrator');
        self::assertFalse($this->data($this->request('PUT', '/opening', nonce: true))['opens_soon']);
    }

    /** @return list<int> */
    private static function pageIds(): array
    {
        return array_values(array_map(intval(...), get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids'])));
    }

    private function business(string $country, string $currency, ?string $state = null): void
    {
        $response = $this->request('PUT', '/setup/answers/business', [
            'legal_name' => 'Acme', 'street' => 'Main Street 1', 'city' => 'Town', 'postcode' => '',
            'country' => $country, 'state' => $state, 'email' => 'shop@example.com', 'currency' => $currency,
        ], nonce: true);
        self::assertSame(200, $response->get_status());
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return list<array{id: int, name: string}>
     */
    private static function rows(array $rows): array
    {
        return array_values(array_map(static function (mixed $row): array {
            self::assertIsArray($row);
            self::assertIsInt($row['id'] ?? null);
            self::assertIsString($row['name'] ?? null);

            return ['id' => $row['id'], 'name' => $row['name']];
        }, $rows));
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

    /**
     * @param list<array{key: string, summary: string, warning: ?string}> $changes
     *
     * @return array{key: string, summary: string, warning: ?string}
     */
    private static function change(array $changes, string $key): array
    {
        foreach ($changes as $change) {
            if ($change['key'] === $key) {
                return $change;
            }
        }
        self::fail("No change {$key} in the plan.");
    }

    /** @param array<array-key, mixed> $answers */
    private static function answer(array $answers, string $field): mixed
    {
        $business = $answers['business'] ?? null;
        self::assertIsArray($business);

        return $business[$field] ?? null;
    }
}
