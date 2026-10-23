<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Invoicing;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Invoice\EInvoiceFile;
use OrillaCart\Extension\Invoice\EInvoiceSender;
use OrillaCart\Invoicing\Application\Dto\InvoiceFile;
use OrillaCart\Invoicing\Application\Dto\UpdateInvoiceSettings;
use OrillaCart\Invoicing\Domain\InvoiceKind;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Invoices and credit notes as EN 16931 XML: UBL as Peppol BIS Billing 3.0 has it, CII as XRechnung has it.
 */
final class EInvoicesTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        // A shop in Bulgaria charging Germany's VAT (OSS), with a book at the reduced rate.
        $this->shop = new TestShop();
        $standard = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $reduced = $this->shop->taxGroups->create(new SaveTaxGroup('Books'))->id;
        $this->shop->taxGroups->addRate($standard, new SaveTaxRate('DE', null, '19', 'MwSt'));
        $this->shop->taxGroups->addRate($reduced, new SaveTaxRate('DE', null, '7', 'MwSt ermäßigt'));
        $this->shop->taxGroups->addRate($standard, new SaveTaxRate('BG', null, '20', 'ДДС'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $standard, reverseCharge: true));
        $this->shop->content->add(10, 'Lamp & shade');
        $this->shop->products->save(10, new SaveProduct('33.33', weight: '1', sku: 'LMP-1'));
        $this->shop->content->add(11, 'Atlas');
        $this->shop->products->save(11, new SaveProduct('10', weight: '1', taxGroupId: $reduced));
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Everywhere', '5')]));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->invoices->updateSettings(new UpdateInvoiceSettings('Lamps Ltd', "1 Vitosha Blvd\n1000 Sofia", 'BG123456789', '201234567', eInvoices: 'ubl'));
        $this->shop->vies->known = ['DE123456789' => 'Muster GmbH'];
    }

    public function testAnInvoiceInUblAddsUp(): void
    {
        $order = $this->paid(3, buyerReference: '04011000-1234512345-06');
        $xml = $this->xpath($this->xml($order), ['u' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2']);

        self::assertSame(['urn:cen.eu:en16931:2017', ''], [$xml->evaluate('string(/u:Invoice/cbc:CustomizationID)'), $xml->evaluate('string(/u:Invoice/cbc:ProfileID)')], 'a consumer has no Peppol ID: plain EN 16931');
        self::assertSame(['380', 'EUR', '04011000-1234512345-06'], [$xml->evaluate('string(//cbc:InvoiceTypeCode)'), $xml->evaluate('string(//cbc:DocumentCurrencyCode)'), $xml->evaluate('string(/u:Invoice/cbc:BuyerReference)')]);
        self::assertSame(['Lamps Ltd', 'BG123456789', '1 Vitosha Blvd', 'Sofia', 'BG'], [
            $xml->evaluate('string(//cac:AccountingSupplierParty//cac:PartyLegalEntity/cbc:RegistrationName)'),
            $xml->evaluate('string(//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID)'),
            $xml->evaluate('string(//cac:AccountingSupplierParty//cbc:StreetName)'),
            $xml->evaluate('string(//cac:AccountingSupplierParty//cbc:CityName)'),
            $xml->evaluate('string(//cac:AccountingSupplierParty//cac:Country/cbc:IdentificationCode)'),
        ]);
        self::assertSame(['Max Muster', 'max@example.de', 'DE'], [
            $xml->evaluate('string(//cac:AccountingCustomerParty//cac:PartyName/cbc:Name)'),
            $xml->evaluate('string(//cac:AccountingCustomerParty//cbc:EndpointID)'),
            $xml->evaluate('string(//cac:AccountingCustomerParty//cac:Country/cbc:IdentificationCode)'),
        ]);
        // Three lamps (99.99), an atlas (10) and shipping (5): each line with its net amount and its one rate.
        self::assertSame([['Lamp & shade', '3', '99.99', 'S', '19', '33.33'], ['Atlas', '1', '10.00', 'S', '7', '10'], ['Courier – Everywhere', '1', '5.00', 'S', '19', '5']], array_map(
            static fn(\DOMNode $line): array => [
                $xml->evaluate('string(cac:Item/cbc:Name)', $line),
                $xml->evaluate('string(cbc:InvoicedQuantity)', $line),
                $xml->evaluate('string(cbc:LineExtensionAmount)', $line),
                $xml->evaluate('string(cac:Item/cac:ClassifiedTaxCategory/cbc:ID)', $line),
                $xml->evaluate('string(cac:Item/cac:ClassifiedTaxCategory/cbc:Percent)', $line),
                $xml->evaluate('string(cac:Price/cbc:PriceAmount)', $line),
            ],
            self::nodes($xml, '//cac:InvoiceLine'),
        ));
        self::assertSame([['S', '19', '104.99', '19.95'], ['S', '7', '10.00', '0.70']], array_map(
            static fn(\DOMNode $group): array => [$xml->evaluate('string(cac:TaxCategory/cbc:ID)', $group), $xml->evaluate('string(cac:TaxCategory/cbc:Percent)', $group), $xml->evaluate('string(cbc:TaxableAmount)', $group), $xml->evaluate('string(cbc:TaxAmount)', $group)],
            self::nodes($xml, '//cac:TaxSubtotal'),
        ));
        self::assertSame(['114.99', '114.99', '20.65', '135.64', '135.64', '0.00'], [
            $xml->evaluate('string(//cbc:LineExtensionAmount[parent::cac:LegalMonetaryTotal])'),
            $xml->evaluate('string(//cbc:TaxExclusiveAmount)'),
            $xml->evaluate('string(/u:Invoice/cac:TaxTotal/cbc:TaxAmount)'),
            $xml->evaluate('string(//cbc:TaxInclusiveAmount)'),
            $xml->evaluate('string(//cbc:PrepaidAmount)'),
            $xml->evaluate('string(//cbc:PayableAmount)'),
        ]);
        self::assertSame($order->total, '135.64');
        self::assertSame('04011000-1234512345-06', $order->buyerReference);
    }

    public function testTheSameInvoiceInCiiForXRechnung(): void
    {
        $this->shop->invoices->updateSettings(new UpdateInvoiceSettings('Lamps Ltd', "1 Vitosha Blvd\n1000 Sofia", 'BG123456789', eInvoices: 'cii', phone: '+359 2 000 0000'));
        $order = $this->paid(3);
        $xml = $this->xpath($this->xml($order), ['rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100', 'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100', 'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100']);

        self::assertSame('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $xml->evaluate('string(//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)'));
        self::assertSame(['380', 'EUR', (string) $order->number], [$xml->evaluate('string(//rsm:ExchangedDocument/ram:TypeCode)'), $xml->evaluate('string(//ram:InvoiceCurrencyCode)'), $xml->evaluate('string(//ram:BuyerReference)')], 'the order number stands in for the buyer\'s reference');
        self::assertSame(3.0, $xml->evaluate('count(//ram:IncludedSupplyChainTradeLineItem)'));
        self::assertSame(['BG123456789', 'VA'], [$xml->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID)'), $xml->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID/@schemeID)')]);
        self::assertSame(['+359 2 000 0000', '1000', 'Sofia'], [$xml->evaluate('string(//ram:SellerTradeParty//ram:TelephoneUniversalCommunication/ram:CompleteNumber)'), $xml->evaluate('string(//ram:SellerTradeParty//ram:PostcodeCode)'), $xml->evaluate('string(//ram:SellerTradeParty//ram:CityName)')], 'the postcode from the address');
        self::assertSame(['114.99', '20.65', '135.64', '135.64', '0.00'], [
            $xml->evaluate('string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount)'),
            $xml->evaluate('string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount)'),
            $xml->evaluate('string(//ram:GrandTotalAmount)'),
            $xml->evaluate('string(//ram:TotalPrepaidAmount)'),
            $xml->evaluate('string(//ram:DuePayableAmount)'),
        ]);
        self::assertSame('2', $xml->evaluate('string(count(/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax))'));
    }

    public function testAReverseChargeSaleSaysSoAndNamesTheBuyersNumber(): void
    {
        $order = $this->paid(1, vatNumber: 'DE123456789');
        self::assertTrue($order->reverseCharge);
        $xml = $this->xpath($this->xml($order), ['cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2']);

        self::assertSame(['AE', '0', 'VATEX-EU-AE', 'Reverse charge', '0.00'], [
            $xml->evaluate('string(//cac:TaxSubtotal/cac:TaxCategory/cbc:ID)'),
            $xml->evaluate('string(//cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)'),
            $xml->evaluate('string(//cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode)'),
            $xml->evaluate('string(//cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason)'),
            $xml->evaluate('string(//cac:TaxSubtotal/cbc:TaxAmount)'),
        ]);
        self::assertSame('DE123456789', $xml->evaluate('string(//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID)'));
        // Both have VAT numbers, so both have Peppol IDs: for Peppol.
        self::assertSame(['urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0', '9926:BG123456789', '9930:DE123456789'], [
            $xml->evaluate('string(//cbc:CustomizationID)'),
            $xml->evaluate('concat(//cac:AccountingSupplierParty//cbc:EndpointID/@schemeID, ":", //cac:AccountingSupplierParty//cbc:EndpointID)'),
            $xml->evaluate('concat(//cac:AccountingCustomerParty//cbc:EndpointID/@schemeID, ":", //cac:AccountingCustomerParty//cbc:EndpointID)'),
        ]);
    }

    public function testTheSellersOwnPeppolIdAndContactGoFirst(): void
    {
        $this->shop->invoices->updateSettings(new UpdateInvoiceSettings('Lamps Ltd', "1 Vitosha Blvd\n1000 Sofia", 'BG123456789', eInvoices: 'ubl', phone: '+359 2 000 0000', email: 'billing@lamps.example', peppolId: '0088:5790000435968'));
        $xml = $this->xpath($this->xml($this->paid(1, vatNumber: 'DE123456789')), ['cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2']);

        self::assertSame(['0088', '5790000435968', '+359 2 000 0000', 'billing@lamps.example'], [
            $xml->evaluate('string(//cac:AccountingSupplierParty//cbc:EndpointID/@schemeID)'),
            $xml->evaluate('string(//cac:AccountingSupplierParty//cbc:EndpointID)'),
            $xml->evaluate('string(//cac:AccountingSupplierParty//cac:Contact/cbc:Telephone)'),
            $xml->evaluate('string(//cac:AccountingSupplierParty//cac:Contact/cbc:ElectronicMail)'),
        ]);
    }

    public function testXRechnungNeedsAPhoneAndTheDetailsAreChecked(): void
    {
        foreach ([
            new UpdateInvoiceSettings('Lamps Ltd', eInvoices: 'cii'),
            new UpdateInvoiceSettings('Lamps Ltd', phone: 'call us'),
            new UpdateInvoiceSettings('Lamps Ltd', phone: '+-'),
            new UpdateInvoiceSettings('Lamps Ltd', email: 'not an email'),
            new UpdateInvoiceSettings('Lamps Ltd', peppolId: 'BG123456789'),
            new UpdateInvoiceSettings('Lamps Ltd', peppolId: '0088:<x>'),
        ] as $index => $refused) {
            try {
                $this->shop->invoices->updateSettings($refused);
                self::fail("case {$index} was taken");
            } catch (RuleViolation) {
            }
        }
        self::assertSame('ubl', $this->shop->invoices->settings()->eInvoices);
    }

    public function testACreditNoteGivesBackTheTaxAndCorrectsTheInvoice(): void
    {
        $order = $this->paid(3);
        $invoice = $this->shop->invoices->ofOrder($order->number)[0]->number;
        $this->shop->refunds->refund($order->number, new MakeRefund('39.66', [new RefundLine(0, 1)]), 7);
        $credit = array_values(array_filter($this->shop->invoices->ofOrder($order->number), static fn($issued): bool => $issued->kind === 'credit_note'))[0];
        $xml = $this->xpath($this->shop->eInvoices->xml($this->shop->invoices->issued($credit->number)), ['n' => 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2', 'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2']);

        self::assertSame(['381', $invoice], [$xml->evaluate('string(/n:CreditNote/cbc:CreditNoteTypeCode)'), $xml->evaluate('string(//cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID)')]);
        // 39.66 holds 6.33 of 19 % on 33.33.
        self::assertSame(['33.33', '6.33', '39.66'], [$xml->evaluate('string(//cbc:TaxExclusiveAmount)'), $xml->evaluate('string(/n:CreditNote/cac:TaxTotal/cbc:TaxAmount)'), $xml->evaluate('string(//cbc:TaxInclusiveAmount)')]);
        self::assertSame(1.0, $xml->evaluate('count(//cac:CreditNoteLine)'));
    }

    public function testTheCustomersEmailCarriesItAndAnAddOnSendsItOn(): void
    {
        $sender = new class implements EInvoiceSender {
            /** @var list<EInvoiceFile> */
            public array $sent = [];

            public function id(): string
            {
                return 'peppol_test';
            }

            public function label(): string
            {
                return 'Peppol test';
            }

            public function send(EInvoiceFile $file): void
            {
                $this->sent[] = $file;
            }
        };
        $this->shop->extensions->eInvoiceSenders[] = $sender;
        $order = $this->paid(1, vatNumber: 'DE123456789');

        $sent = $sender->sent;
        self::assertCount(1, $sent);
        self::assertSame([$this->shop->invoices->ofOrder($order->number)[0]->number, 'invoice', 'ubl', 'DE123456789', 'DE'], [$sent[0]->number, $sent[0]->kind, $sent[0]->format, $sent[0]->buyerTaxNumber, $sent[0]->buyerCountry]);
        self::assertSame('9930:DE123456789', $sent[0]->buyerPeppolId);
        self::assertStringStartsWith('<?xml', $sent[0]->xml);
        self::assertNotNull($this->shop->invoices->ofOrder($order->number)[0]->xmlUrl);

        self::assertSame([$sent[0]->number . '.pdf', $sent[0]->number . '.xml'], array_map(static fn(InvoiceFile $file): string => $file->filename, $this->shop->invoiceAttachments->files($order->number, InvoiceKind::Invoice, null)));
    }

    public function testWithoutAFormatNothingIsMadeOrSent(): void
    {
        $this->shop->invoices->updateSettings(new UpdateInvoiceSettings('Lamps Ltd', eInvoices: 'none'));
        $order = $this->paid(1);

        self::assertNull($this->shop->invoices->ofOrder($order->number)[0]->xmlUrl);
        self::assertNull($this->shop->eInvoices->format());
    }

    private function paid(int $lamps, ?string $vatNumber = null, ?string $buyerReference = null): OrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: $lamps))->newToken);
        $this->shop->carts->add($owner, new AddToCart(11));
        $address = new AddressData('Max', 'Muster', 'Hauptstr. 1', 'Berlin', '10115', 'DE', '+49 30 000000');
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('max@example.de', $address, shippingOption: $this->shop->checkout->summary($owner, new CheckoutDetails('DE'))->shippingOption, payment: 'bank_transfer', vatNumber: $vatNumber, buyerReference: $buyerReference));
        $this->shop->orders->markPaidByShop($placed->number, '', 7);

        return $this->shop->orders->order($placed->number);
    }

    private function xml(OrderView $order): string
    {
        return $this->shop->eInvoices->xml($this->shop->invoices->issued($this->shop->invoices->ofOrder($order->number)[0]->number));
    }

    /** @return list<\DOMNode> */
    private static function nodes(\DOMXPath $xpath, string $query): array
    {
        $found = $xpath->query($query);
        self::assertInstanceOf(\DOMNodeList::class, $found);
        $nodes = [];
        foreach ($found as $node) {
            if ($node instanceof \DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, string> $namespaces
     */
    private function xpath(string $xml, array $namespaces): \DOMXPath
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml, \LIBXML_NONET), 'well-formed');
        $xpath = new \DOMXPath($dom);
        foreach ($namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }
}
