<?php

declare(strict_types=1);

namespace OrillaCart\Tests\EInvoices;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Invoicing\Application\Dto\UpdateInvoiceSettings;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Settings\Application\Dto\CurrencySettingsView;
use OrillaCart\Settings\Application\Dto\OfferedCurrencyView;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxExemption;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shop's e-invoices through the official validators: UBL through the UBL 2.1 schema and OpenPeppol's BIS Billing
 * 3.0 rules (EN 16931's and Peppol's own), CII through KoSIT's validator with the XRechnung rules. They need Java and
 * are fetched by bin/einvoice-validators: npm run test:einvoices. Warnings fail too, so nothing goes unread.
 */
final class EInvoiceValidationTest extends TestCase
{
    private TestShop $shop;

    public static function setUpBeforeClass(): void
    {
        if (!is_file(self::dir() . '/ready-1')) {
            self::markTestSkipped('Run bin/einvoice-validators first (npm run test:einvoices does).');
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function documents(): iterable
    {
        foreach (['ubl', 'cii'] as $format) {
            foreach (['two_rates', 'reverse_charge', 'exempt', 'export', 'not_subject', 'other_currency', 'credit_note', 'business', 'business_credit_note'] as $case) {
                yield "{$case} in {$format}" => [$case, $format];
            }
        }
    }

    #[DataProvider('documents')]
    public function testTheValidatorsAcceptIt(string $case, string $format): void
    {
        $xml = $this->document($case, $format);
        $file = self::dir() . "/out/{$case}-{$format}.xml";
        @mkdir(\dirname($file), 0o777, true);
        file_put_contents($file, $xml);

        self::assertSame([], $format === 'ubl' ? self::peppol($file, $xml) : self::xrechnung($file), $file);
    }

    private function document(string $case, string $format): string
    {
        // A shop in Bulgaria charging Germany's VAT (OSS), with a book at the reduced rate.
        $this->shop = new TestShop();
        $standard = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $reduced = $this->shop->taxGroups->create(new SaveTaxGroup('Books'))->id;
        $this->shop->taxGroups->addRate($standard, new SaveTaxRate('DE', null, '19', 'MwSt'));
        $this->shop->taxGroups->addRate($reduced, new SaveTaxRate('DE', null, '7', 'MwSt ermäßigt'));
        $this->shop->taxGroups->addRate($standard, new SaveTaxRate('BG', null, '20', 'ДДС'));
        $this->shop->taxGroups->addRate($reduced, new SaveTaxRate('BG', null, '9', 'ДДС'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $standard, reverseCharge: true));
        $this->shop->content->add(10, 'Lamp & shade');
        $this->shop->products->save(10, new SaveProduct('33.33', weight: '1', sku: 'LMP-1'));
        $this->shop->content->add(11, 'Atlas');
        $this->shop->products->save(11, new SaveProduct('10', weight: '1', taxGroupId: $reduced));
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Everywhere', '5')]));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->invoices->updateSettings(new UpdateInvoiceSettings('Lamps Ltd', "1 Vitosha Blvd\n1000 Sofia", $case === 'not_subject' ? '' : 'BG123456789', '201234567', eInvoices: $format, phone: '+359 2 000 0000', email: 'invoices@lamps.example'));
        // A business in Germany buys under the reverse charge; one in Bulgaria, the shop's country, pays its VAT.
        $this->shop->vies->known = ['DE123456789' => 'Muster GmbH', 'BG175074752' => 'Sofia Lights OOD'];
        if ($case === 'other_currency') {
            $this->shop->currencies->save(new CurrencySettingsView([new OfferedCurrencyView('USD', '1.1')]));
            $this->shop->chosenCurrency->currency = 'USD';
        }

        if ($case === 'exempt') {
            $customer = $this->shop->accounts->add('max@example.de');
            $this->shop->taxExemptions->add($customer, new SaveTaxExemption('resale', 'DE-RS-1'));
            $owner = new CartOwner(null, $customer);
            $this->shop->carts->add($owner, new AddToCart(10, quantity: 3));
        } else {
            $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 3))->newToken);
        }
        $this->shop->carts->add($owner, new AddToCart(11));
        $address = match ($case) {
            'export' => new AddressData('Ann', 'Smith', '1 Main St', 'Austin', '73301', 'US', '+1 512 0000000', state: 'TX'),
            'not_subject' => new AddressData('Eleni', 'Papadopoulou', 'Odos 1', 'Athens', '10552', 'GR', '+30 21 0000000'),
            'business', 'business_credit_note' => new AddressData('Ivan', 'Petrov', '5 Rakovski St', 'Sofia', '1000', 'BG', '+359 2 111 1111'),
            default => new AddressData('Max', 'Muster', 'Hauptstr. 1', 'Berlin', '10115', 'DE', '+49 30 000000'),
        };
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('max@example.de', $address, shippingOption: $this->shop->checkout->summary($owner, new CheckoutDetails($address->country))->shippingOption, payment: 'bank_transfer', vatNumber: match ($case) {
            'reverse_charge' => 'DE123456789',
            'business', 'business_credit_note' => 'BG175074752',
            default => null,
        }, buyerReference: '04011000-1234512345-06'));
        $this->shop->orders->markPaidByShop($placed->number, '', 7);
        $number = $this->shop->invoices->ofOrder($placed->number)[0]->number;
        if ($case === 'credit_note' || $case === 'business_credit_note') {
            $this->shop->refunds->refund($placed->number, new MakeRefund('39.66', [new RefundLine(0, 1)]), 7);
            $number = array_values(array_filter($this->shop->invoices->ofOrder($placed->number), static fn($issued): bool => $issued->kind === 'credit_note'))[0]->number;
        }

        return $this->shop->eInvoices->xml($this->shop->invoices->issued($number));
    }

    /**
     * The UBL 2.1 schema, then EN 16931's rules and Peppol's, as SVRL.
     *
     * @return list<string> what failed
     */
    private static function peppol(string $file, string $xml): array
    {
        $failed = [];
        $dom = new \DOMDocument();
        $dom->loadXML($xml, \LIBXML_NONET);
        $schema = self::dir() . '/xrechnung/resources/ubl/2.1/xsd/maindoc/UBL-' . ($dom->documentElement?->localName === 'CreditNote' ? 'CreditNote' : 'Invoice') . '-2.1.xsd';
        $errors = libxml_use_internal_errors(true);
        if (!$dom->schemaValidate($schema)) {
            foreach (libxml_get_errors() as $error) {
                $failed[] = 'schema: ' . trim($error->message) . ' (line ' . $error->line . ')';
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        // Peppol's own rules for a document for Peppol; EN 16931's for any.
        $forPeppol = new \DOMXPath($dom)->evaluate("string(/*/*[local-name() = 'CustomizationID'])") === 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
        foreach ($forPeppol ? ['CEN-EN16931-UBL', 'PEPPOL-EN16931-UBL'] : ['CEN-EN16931-UBL'] as $rules) {
            $report = $file . '.' . $rules . '.svrl';
            self::java(['-cp', self::dir() . '/dl/validator.jar', 'net.sf.saxon.Transform', '-s:' . $file, '-xsl:' . self::dir() . "/peppol/xsl/{$rules}.xsl", '-o:' . $report]);
            $svrl = new \DOMXPath(self::load($report));
            $svrl->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');
            foreach (self::elements($svrl, '//svrl:failed-assert | //svrl:successful-report') as $assert) {
                $failed[] = \sprintf('%s %s [%s]: %s', $rules, $assert->getAttribute('id'), $assert->getAttribute('flag'), trim($assert->textContent));
            }
        }

        return $failed;
    }

    /**
     * KoSIT's validator with the XRechnung configuration: the CII schema, EN 16931's rules and XRechnung's.
     *
     * @return list<string> what failed
     */
    private static function xrechnung(string $file): array
    {
        $out = self::dir() . '/out/kosit';
        @mkdir($out, 0o777, true);
        self::java(['-jar', self::dir() . '/dl/validator.jar', '-s', self::dir() . '/xrechnung/scenarios.xml', '-r', self::dir() . '/xrechnung', '-o', $out, $file], [0, 1]);
        $report = new \DOMXPath(self::load($out . '/' . basename($file, '.xml') . '-report.xml'));
        $report->registerNamespace('rep', 'http://www.xoev.de/de/validator/varl/1');
        $report->registerNamespace('s', 'http://www.xoev.de/de/validator/framework/1/scenarios');
        $report->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');
        $failed = [];
        if ($report->evaluate('string(//rep:scenarioMatched/s:scenario/s:name)') === '') {
            $failed[] = 'no XRechnung scenario matched';
        }
        foreach (self::elements($report, '//rep:message') as $message) {
            $failed[] = \sprintf('%s [%s]: %s', $message->getAttribute('code'), $message->getAttribute('level'), trim($message->textContent));
        }
        foreach (self::elements($report, '//svrl:failed-assert | //svrl:successful-report') as $assert) {
            $failed[] = \sprintf('%s [%s]: %s', $assert->getAttribute('id'), $assert->getAttribute('flag'), trim($assert->textContent));
        }
        if ($failed === [] && $report->evaluate('count(//rep:assessment/rep:accept)') < 1) {
            $failed[] = 'rejected';
        }

        return array_values(array_unique($failed));
    }

    /**
     * @param list<string> $arguments
     * @param list<int> $codes the exit codes that mean it ran
     */
    private static function java(array $arguments, array $codes = [0]): void
    {
        $command = implode(' ', array_map(escapeshellarg(...), ['java', '--enable-native-access=ALL-UNNAMED', ...$arguments])) . ' 2>&1';
        exec($command, $output, $code);
        self::assertContains($code, $codes, implode("\n", $output));
    }

    /** @return list<\DOMElement> */
    private static function elements(\DOMXPath $xpath, string $query): array
    {
        $found = $xpath->query($query);

        return $found === false ? [] : array_values(array_filter(iterator_to_array($found), static fn($node): bool => $node instanceof \DOMElement));
    }

    /** KoSIT's validator wants the repository's own path, without "..". */
    private static function dir(): string
    {
        return \dirname(__DIR__, 2) . '/var/einvoice-validators';
    }

    private static function load(string $file): \DOMDocument
    {
        $dom = new \DOMDocument();
        self::assertTrue(is_file($file) && $dom->load($file, \LIBXML_NONET), $file);

        return $dom;
    }
}
