<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

use OrillaCart\Geography\Application\Dto\LocationView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Invoicing\Application\Dto\InvoiceDocument;
use OrillaCart\Invoicing\Domain\InvoiceSettings;
use OrillaCart\Invoicing\Domain\InvoiceSettingsRepository;
use OrillaCart\Notifications\Application\EmailWriter;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\LineTaxView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;

/**
 * Invoices and credit notes as EN 16931 XML, the EU's semantic model of an electronic invoice, in one of its two
 * syntaxes: UBL 2.1 as Peppol BIS Billing 3.0 has it, or UN/CEFACT CII as Germany's XRechnung has it.
 *
 * Each line goes with its net amount (the price after its discounts, before tax) and one VAT category: S at the
 * rates it was charged (added up when a place charges several), E for a sale under the customer's exemption, AE
 * under the reverse charge, G for an export, O when the shop charges no VAT at all. A credit note gives back, at
 * each rate, the tax it gave back and what that tax was on. The shop's own email is its electronic address and the
 * customer's theirs; sending the file through Peppol is an access point's job, for an add-on.
 *
 * @phpstan-type Line array{id: string, name: string, sku: ?string, quantity: int, net: Money, category: string, percent: string, tax: Money}
 * @phpstan-type Party array{name: string, street: string, city: string, postcode: string, country: string, state?: ?string, vat: ?string, company?: ?string, email: string}
 * @phpstan-type Model array{credit: bool, number: string, issued: string, currency: string, decimals: int, corrects: ?string, buyerReference: string, salesOrder: string, delivered: string, seller: Party, buyer: Party, payment: string, paid: bool, lines: list<Line>, breakdown: list<array{category: string, percent: string, net: Money, tax: Money, reason: ?string, reasonCode: ?string}>, net: Money, tax: Money, gross: Money, prepaid: Money, rounding: Money, taxCurrency: ?array{currency: string, total: string}}
 */
final readonly class EInvoices
{
    public const string UBL_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const string UBL_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    private const string CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const string CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    public const string CII = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const string RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const string UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    private const string PEPPOL = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
    private const string XRECHNUNG = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';
    private const string PROCESS = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    /** Why a category charges no VAT, by its code: the reason codes of the VATEX list. */
    private const array EXEMPT = [
        'AE' => ['VATEX-EU-AE', 'Reverse charge'],
        'G' => ['VATEX-EU-G', 'Export outside the EU'],
        'O' => ['VATEX-EU-O', 'Not subject to VAT'],
    ];

    public function __construct(
        private InvoiceSettingsRepository $settings,
        private SettingsService $shop,
        private GeographyService $geography,
        private EmailWriter $emails,
    ) {}

    /** The format the shop chose, InvoiceSettings::UBL or CII; null when it makes no e-invoices. */
    public function format(): ?string
    {
        $format = $this->settings->load()->eInvoices;

        return $format === InvoiceSettings::NO_EINVOICES ? null : $format;
    }

    public function filename(InvoiceDocument $document): string
    {
        return $document->invoice->number . '.xml';
    }

    /**
     * The document as XML in the format given, or the shop's.
     *
     * @throws \LogicException when the shop makes no e-invoices and none is given
     */
    public function xml(InvoiceDocument $document, ?string $format = null): string
    {
        $format ??= $this->format() ?? throw new \LogicException('The shop makes no e-invoices.');
        $model = $this->model($document);

        return $format === InvoiceSettings::CII ? $this->cii($model) : $this->ubl($model);
    }

    /**
     * What both syntaxes say, worked out once.
     *
     * @return Model
     */
    private function model(InvoiceDocument $document): array
    {
        $order = $document->order;
        $invoice = $document->invoice;
        $credit = $invoice->kind === 'credit_note';
        $decimals = new Currency($invoice->currency)->decimals();
        $noTax = $this->noTaxCategory($document);
        $lines = $credit ? self::creditLines($document, $noTax, $decimals) : self::invoiceLines($document, $noTax);

        $groups = [];
        foreach ($lines as $line) {
            $key = $line['category'] . '|' . $line['percent'];
            $groups[$key] ??= ['category' => $line['category'], 'percent' => $line['percent'], 'net' => Money::zero(), 'tax' => Money::zero(), 'reason' => self::EXEMPT[$line['category']][1] ?? null, 'reasonCode' => self::EXEMPT[$line['category']][0] ?? null];
            $groups[$key]['net'] = $groups[$key]['net']->add($line['net']);
            $groups[$key]['tax'] = $groups[$key]['tax']->add($line['tax']);
        }
        if ($order->taxExemption !== null) {
            foreach ($groups as $key => $group) {
                if ($group['category'] === 'E') {
                    $groups[$key]['reason'] = 'Exempt under certificate ' . $order->taxExemption['certificate'];
                }
            }
        }
        $net = array_reduce($lines, static fn(Money $sum, array $line): Money => $sum->add($line['net']->rounded($decimals)), Money::zero());
        $tax = array_reduce($groups, static fn(Money $sum, array $group): Money => $sum->add($group['tax']->rounded($decimals)), Money::zero());
        $gross = $net->add($tax);
        $total = Money::of($invoice->amount);
        $shop = $this->shop->current();
        $seller = $document->seller;
        [$street, $city] = self::addressLines($seller['address'] ?? '');
        $fulfilled = $order->fulfilledAt ?? $order->paidAt ?? $invoice->issuedAt;

        return [
            'credit' => $credit,
            'number' => $invoice->number,
            'issued' => substr($invoice->issuedAt, 0, 10),
            'currency' => $invoice->currency,
            'decimals' => $decimals,
            'corrects' => $document->corrects,
            'buyerReference' => $order->buyerReference ?? (string) $order->number,
            'salesOrder' => (string) $order->number,
            'delivered' => substr($fulfilled, 0, 10),
            'seller' => [
                'name' => ($seller['legal_name'] ?? '') !== '' ? $seller['legal_name'] : $this->emails->shopAddress(),
                'street' => $street,
                'city' => $city,
                'postcode' => $shop->shopPostcode ?? '',
                'country' => $shop->shopCountry ?? '',
                'vat' => ($seller['vat_number'] ?? '') === '' ? null : $seller['vat_number'],
                'company' => ($seller['company_number'] ?? '') === '' ? null : $seller['company_number'],
                'email' => $this->emails->shopAddress(),
            ],
            'buyer' => self::party($order->billing, $order->vatNumber, $order->email),
            'payment' => self::paymentMeans($order->paymentGateway),
            'paid' => $order->paidAt !== null,
            'lines' => $lines,
            'breakdown' => array_values($groups),
            'net' => $net,
            'tax' => $tax,
            'gross' => $gross,
            // Paid when issued: invoices are issued as orders are paid, credit notes as money goes back.
            'prepaid' => $total,
            'rounding' => $total->subtract($gross),
            'taxCurrency' => $document->inShopCurrency === null ? null : ['currency' => $document->inShopCurrency['currency'], 'total' => $document->inShopCurrency['total']],
        ];
    }

    /**
     * @return list<Line>
     */
    private static function invoiceLines(InvoiceDocument $document, string $noTax): array
    {
        $lines = [];
        foreach ([...$document->order->lines, ...$document->order->charges] as $index => $line) {
            $lines[] = self::line((string) ($index + 1), $line, $document->order->pricesIncludeTax, $noTax);
        }

        return $lines;
    }

    /**
     * @return Line
     */
    private static function line(string $id, OrderLineView $line, bool $includesTax, string $noTax): array
    {
        /** @var list<LineTaxView> $taxes */
        $taxes = $line->taxes;
        $tax = Money::of($line->tax);
        if ($taxes === []) {
            $net = Money::of($line->total)->subtract(Money::of($line->discount))->subtract($includesTax ? $tax : Money::zero());
            $category = $noTax;
            $percent = '0';
        } else {
            $net = Money::of($taxes[0]->taxable);
            $charged = array_values(array_filter($taxes, static fn(LineTaxView $rate): bool => !$rate->exempt));
            if ($charged === []) {
                $category = 'E';
                $percent = '0';
            } else {
                $compound = array_any($charged, static fn(LineTaxView $rate): bool => $rate->compound);
                // One rate a line: a place charging several (a state and a county) gives their sum.
                $percent = $compound && !$net->isZero()
                    ? self::decimal(round((float) (string) $tax / (float) (string) $net * 100, 2))
                    : self::decimal(array_sum(array_map(static fn(LineTaxView $rate): float => (float) $rate->percent, $charged)));
                $category = (float) $percent > 0 ? 'S' : 'Z';
            }
        }

        return ['id' => $id, 'name' => $line->name, 'sku' => $line->sku, 'quantity' => max(1, $line->quantity), 'net' => $net, 'category' => $category, 'percent' => $percent, 'tax' => $tax];
    }

    /**
     * A credit note's lines: at each rate, what the tax it gives back was on; anything given back besides, at the
     * order's category without tax.
     *
     * @return list<Line>
     */
    private static function creditLines(InvoiceDocument $document, string $noTax, int $decimals): array
    {
        $lines = [];
        $left = Money::of($document->invoice->amount);
        foreach ($document->taxes as $index => $tax) {
            $amount = Money::of($tax['amount']);
            $percent = self::decimal((float) $tax['percent']);
            $net = (float) $percent > 0 ? $amount->proportion(Money::of('100'), Money::of($percent))->rounded($decimals) : Money::zero();
            $lines[] = ['id' => (string) ($index + 1), 'name' => \sprintf('Refund of order #%s: %s %s%%', $document->order->number, $tax['name'], $percent), 'sku' => null, 'quantity' => 1, 'net' => $net, 'category' => 'S', 'percent' => $percent, 'tax' => $amount];
            $left = $left->subtract($net)->subtract($amount);
        }
        if ($lines === [] || $left->compare(Money::of('0.05')) > 0) {
            $lines[] = ['id' => (string) (\count($lines) + 1), 'name' => \sprintf('Refund of order #%s', $document->order->number), 'sku' => null, 'quantity' => 1, 'net' => $lines === [] ? Money::of($document->invoice->amount) : $left, 'category' => $noTax, 'percent' => '0', 'tax' => Money::zero()];
        }

        return $lines;
    }

    /**
     * The category of what the order charges no tax on: the reverse charge, an export from the EU, or a shop
     * that charges no VAT.
     */
    private function noTaxCategory(InvoiceDocument $document): string
    {
        $order = $document->order;
        if ($order->reverseCharge) {
            return 'AE';
        }
        $shop = $this->shop->current();
        $from = $shop->shopCountry === null ? null : $this->geography->locate($shop->shopCountry, null);
        $to = $this->geography->locate(($order->shippingAddress ?? $order->billing)->country, null);
        if ($order->importScheme !== null || ($from instanceof LocationView && $from->inEu && $to instanceof LocationView && !$to->inEu)) {
            return 'G';
        }

        return ($document->seller['vat_number'] ?? '') === '' ? 'O' : 'E';
    }

    /**
     * @return Party
     */
    private static function party(AddressData $address, ?string $vat, string $email): array
    {
        return ['name' => trim($address->firstName . ' ' . $address->lastName), 'street' => $address->street, 'city' => $address->city, 'postcode' => $address->postcode, 'country' => $address->country, 'state' => $address->state, 'vat' => $vat, 'email' => $email];
    }

    /** UNCL 4461: cash, cheque, an online payment service, or not said. */
    private static function paymentMeans(?string $gateway): string
    {
        return match ($gateway) {
            'cod' => '10',
            'cheque' => '20',
            'paypal' => '68',
            default => '1',
        };
    }

    /**
     * The seller's address as the shop wrote it: its first line the street, its last the city (without a postcode
     * written before it).
     *
     * @return array{string, string}
     */
    private static function addressLines(string $address): array
    {
        $parts = preg_split('/\R|,/', $address);
        $lines = array_values(array_filter(array_map(trim(...), $parts === false ? [] : $parts), static fn(string $line): bool => $line !== ''));
        $street = $lines[0] ?? '';
        $city = \count($lines) > 1 ? (string) preg_replace('/^\d[\d\s-]*\s+/', '', $lines[\count($lines) - 1]) : '';

        return [$street, $city];
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * @param Model $model
     */
    private function ubl(array $model): string
    {
        $credit = $model['credit'];
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS($credit ? self::UBL_CREDIT_NOTE : self::UBL_INVOICE, $credit ? 'CreditNote' : 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $dom->appendChild($root);
        $cbc = static function (\DOMElement $parent, string $name, string $value, ?string $attribute = null, string $attributeValue = '') use ($dom): \DOMElement {
            $element = $dom->createElementNS(self::CBC, 'cbc:' . $name);
            $element->appendChild($dom->createTextNode($value));
            if ($attribute !== null) {
                $element->setAttribute($attribute, $attributeValue);
            }
            $parent->appendChild($element);

            return $element;
        };
        $cac = static function (\DOMElement $parent, string $name) use ($dom): \DOMElement {
            $element = $dom->createElementNS(self::CAC, 'cac:' . $name);
            $parent->appendChild($element);

            return $element;
        };
        $currency = $model['currency'];
        $amount = static fn(Money $value): string => $value->format($model['decimals']);
        $money = static fn(\DOMElement $parent, string $name, Money $value) => $cbc($parent, $name, $amount($value), 'currencyID', $currency);

        $cbc($root, 'CustomizationID', self::PEPPOL);
        $cbc($root, 'ProfileID', self::PROCESS);
        $cbc($root, 'ID', $model['number']);
        $cbc($root, 'IssueDate', $model['issued']);
        $cbc($root, $credit ? 'CreditNoteTypeCode' : 'InvoiceTypeCode', $credit ? '381' : '380');
        $cbc($root, 'DocumentCurrencyCode', $currency);
        if ($model['taxCurrency'] !== null) {
            $cbc($root, 'TaxCurrencyCode', $model['taxCurrency']['currency']);
        }
        $cbc($root, 'BuyerReference', $model['buyerReference']);
        $reference = $cac($root, 'OrderReference');
        $cbc($reference, 'ID', $model['buyerReference']);
        $cbc($reference, 'SalesOrderID', $model['salesOrder']);
        if ($model['corrects'] !== null) {
            $cbc($cac($cac($root, 'BillingReference'), 'InvoiceDocumentReference'), 'ID', $model['corrects']);
        }
        foreach (['AccountingSupplierParty' => $model['seller'], 'AccountingCustomerParty' => $model['buyer']] as $role => $who) {
            $party = $cac($cac($root, $role), 'Party');
            $cbc($party, 'EndpointID', $who['email'], 'schemeID', 'EM');
            $cbc($cac($party, 'PartyName'), 'Name', $who['name']);
            $address = $cac($party, 'PostalAddress');
            if ($who['street'] !== '') {
                $cbc($address, 'StreetName', $who['street']);
            }
            if ($who['city'] !== '') {
                $cbc($address, 'CityName', $who['city']);
            }
            if ($who['postcode'] !== '') {
                $cbc($address, 'PostalZone', $who['postcode']);
            }
            if (($who['state'] ?? null) !== null) {
                $cbc($address, 'CountrySubentity', $who['state']);
            }
            $cbc($cac($address, 'Country'), 'IdentificationCode', $who['country']);
            if ($who['vat'] !== null) {
                $scheme = $cac($party, 'PartyTaxScheme');
                $cbc($scheme, 'CompanyID', $who['vat']);
                $cbc($cac($scheme, 'TaxScheme'), 'ID', 'VAT');
            }
            $legal = $cac($party, 'PartyLegalEntity');
            $cbc($legal, 'RegistrationName', $who['name']);
            if (($who['company'] ?? null) !== null) {
                $cbc($legal, 'CompanyID', $who['company']);
            }
            $cbc($cac($party, 'Contact'), 'ElectronicMail', $who['email']);
        }
        $cbc($cac($root, 'Delivery'), 'ActualDeliveryDate', $model['delivered']);
        $cbc($cac($root, 'PaymentMeans'), 'PaymentMeansCode', $model['payment']);
        if ($model['paid']) {
            $cbc($cac($root, 'PaymentTerms'), 'Note', 'Paid');
        }
        $total = $cac($root, 'TaxTotal');
        $money($total, 'TaxAmount', $model['tax']);
        foreach ($model['breakdown'] as $group) {
            $subtotal = $cac($total, 'TaxSubtotal');
            $money($subtotal, 'TaxableAmount', $group['net']->rounded($model['decimals']));
            $money($subtotal, 'TaxAmount', $group['tax']->rounded($model['decimals']));
            $category = $cac($subtotal, 'TaxCategory');
            $cbc($category, 'ID', $group['category']);
            $cbc($category, 'Percent', $group['percent']);
            if ($group['category'] !== 'S' && $group['category'] !== 'Z') {
                if ($group['reasonCode'] !== null) {
                    $cbc($category, 'TaxExemptionReasonCode', $group['reasonCode']);
                }
                $cbc($category, 'TaxExemptionReason', $group['reason'] ?? 'Exempt from VAT');
            }
            $cbc($cac($category, 'TaxScheme'), 'ID', 'VAT');
        }
        if ($model['taxCurrency'] !== null) {
            $cbc($cac($root, 'TaxTotal'), 'TaxAmount', $model['taxCurrency']['total'], 'currencyID', $model['taxCurrency']['currency']);
        }
        $sums = $cac($root, 'LegalMonetaryTotal');
        $money($sums, 'LineExtensionAmount', $model['net']);
        $money($sums, 'TaxExclusiveAmount', $model['net']);
        $money($sums, 'TaxInclusiveAmount', $model['gross']);
        $money($sums, 'PrepaidAmount', $model['prepaid']);
        if (!$model['rounding']->isZero()) {
            $money($sums, 'PayableRoundingAmount', $model['rounding']);
        }
        $money($sums, 'PayableAmount', $model['gross']->subtract($model['prepaid'])->add($model['rounding']));
        foreach ($model['lines'] as $line) {
            $element = $cac($root, $credit ? 'CreditNoteLine' : 'InvoiceLine');
            $cbc($element, 'ID', $line['id']);
            $cbc($element, $credit ? 'CreditedQuantity' : 'InvoicedQuantity', (string) $line['quantity'], 'unitCode', 'C62');
            $money($element, 'LineExtensionAmount', $line['net']->rounded($model['decimals']));
            $item = $cac($element, 'Item');
            $cbc($item, 'Name', $line['name']);
            if ($line['sku'] !== null && $line['sku'] !== '') {
                $cbc($cac($item, 'SellersItemIdentification'), 'ID', $line['sku']);
            }
            $category = $cac($item, 'ClassifiedTaxCategory');
            $cbc($category, 'ID', $line['category']);
            $cbc($category, 'Percent', $line['percent']);
            $cbc($cac($category, 'TaxScheme'), 'ID', 'VAT');
            $cbc($cac($element, 'Price'), 'PriceAmount', self::unitPrice($line['net'], $line['quantity']), 'currencyID', $currency);
        }

        return (string) $dom->saveXML();
    }

    /**
     * @param Model $model
     */
    private function cii(array $model): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(self::CII, 'rsm:CrossIndustryInvoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::UDT);
        $dom->appendChild($root);
        $add = static function (\DOMElement $parent, string $name, ?string $value = null, ?string $attribute = null, string $attributeValue = '') use ($dom): \DOMElement {
            [$prefix] = explode(':', $name);
            $element = $dom->createElementNS(match ($prefix) {
                'rsm' => self::CII,
                'udt' => self::UDT,
                default => self::RAM,
            }, $name);
            if ($value !== null) {
                $element->appendChild($dom->createTextNode($value));
            }
            if ($attribute !== null) {
                $element->setAttribute($attribute, $attributeValue);
            }
            $parent->appendChild($element);

            return $element;
        };
        $currency = $model['currency'];
        $amount = static fn(Money $value): string => $value->format($model['decimals']);
        $date = static fn(\DOMElement $parent, string $day) => $add($parent, 'udt:DateTimeString', str_replace('-', '', $day), 'format', '102');

        $context = $add($root, 'rsm:ExchangedDocumentContext');
        $add($add($context, 'ram:BusinessProcessSpecifiedDocumentContextParameter'), 'ram:ID', self::PROCESS);
        $add($add($context, 'ram:GuidelineSpecifiedDocumentContextParameter'), 'ram:ID', self::XRECHNUNG);
        $document = $add($root, 'rsm:ExchangedDocument');
        $add($document, 'ram:ID', $model['number']);
        $add($document, 'ram:TypeCode', $model['credit'] ? '381' : '380');
        $date($add($document, 'ram:IssueDateTime'), $model['issued']);

        $transaction = $add($root, 'rsm:SupplyChainTradeTransaction');
        foreach ($model['lines'] as $line) {
            $item = $add($transaction, 'ram:IncludedSupplyChainTradeLineItem');
            $add($add($item, 'ram:AssociatedDocumentLineDocument'), 'ram:LineID', $line['id']);
            $product = $add($item, 'ram:SpecifiedTradeProduct');
            if ($line['sku'] !== null && $line['sku'] !== '') {
                $add($product, 'ram:SellerAssignedID', $line['sku']);
            }
            $add($product, 'ram:Name', $line['name']);
            $add($add($add($item, 'ram:SpecifiedLineTradeAgreement'), 'ram:NetPriceProductTradePrice'), 'ram:ChargeAmount', self::unitPrice($line['net'], $line['quantity']));
            $add($add($item, 'ram:SpecifiedLineTradeDelivery'), 'ram:BilledQuantity', (string) $line['quantity'], 'unitCode', 'C62');
            $settlement = $add($item, 'ram:SpecifiedLineTradeSettlement');
            $tax = $add($settlement, 'ram:ApplicableTradeTax');
            $add($tax, 'ram:TypeCode', 'VAT');
            $add($tax, 'ram:CategoryCode', $line['category']);
            $add($tax, 'ram:RateApplicablePercent', $line['percent']);
            $add($add($settlement, 'ram:SpecifiedTradeSettlementLineMonetarySummation'), 'ram:LineTotalAmount', $amount($line['net']->rounded($model['decimals'])));
        }

        $agreement = $add($transaction, 'ram:ApplicableHeaderTradeAgreement');
        $add($agreement, 'ram:BuyerReference', $model['buyerReference']);
        foreach (['ram:SellerTradeParty' => $model['seller'], 'ram:BuyerTradeParty' => $model['buyer']] as $role => $who) {
            $party = $add($agreement, $role);
            $add($party, 'ram:Name', $who['name']);
            if (($who['company'] ?? null) !== null) {
                $add($add($party, 'ram:SpecifiedLegalOrganization'), 'ram:ID', $who['company']);
            }
            $contact = $add($party, 'ram:DefinedTradeContact');
            $add($contact, 'ram:PersonName', $who['name']);
            $add($add($contact, 'ram:EmailURIUniversalCommunication'), 'ram:URIID', $who['email']);
            $address = $add($party, 'ram:PostalTradeAddress');
            if ($who['postcode'] !== '') {
                $add($address, 'ram:PostcodeCode', $who['postcode']);
            }
            if ($who['street'] !== '') {
                $add($address, 'ram:LineOne', $who['street']);
            }
            if ($who['city'] !== '') {
                $add($address, 'ram:CityName', $who['city']);
            }
            $add($address, 'ram:CountryID', $who['country']);
            if (($who['state'] ?? null) !== null) {
                $add($address, 'ram:CountrySubDivisionName', $who['state']);
            }
            $add($add($party, 'ram:URIUniversalCommunication'), 'ram:URIID', $who['email'], 'schemeID', 'EM');
            if ($who['vat'] !== null) {
                $add($add($party, 'ram:SpecifiedTaxRegistration'), 'ram:ID', $who['vat'], 'schemeID', 'VA');
            }
        }
        $add($add($agreement, 'ram:BuyerOrderReferencedDocument'), 'ram:IssuerAssignedID', $model['buyerReference']);

        $delivery = $add($transaction, 'ram:ApplicableHeaderTradeDelivery');
        $date($add($add($delivery, 'ram:ActualDeliverySupplyChainEvent'), 'ram:OccurrenceDateTime'), $model['delivered']);

        $settlement = $add($transaction, 'ram:ApplicableHeaderTradeSettlement');
        if ($model['taxCurrency'] !== null) {
            $add($settlement, 'ram:TaxCurrencyCode', $model['taxCurrency']['currency']);
        }
        $add($settlement, 'ram:InvoiceCurrencyCode', $currency);
        $add($add($settlement, 'ram:SpecifiedTradeSettlementPaymentMeans'), 'ram:TypeCode', $model['payment']);
        foreach ($model['breakdown'] as $group) {
            $tax = $add($settlement, 'ram:ApplicableTradeTax');
            $add($tax, 'ram:CalculatedAmount', $amount($group['tax']->rounded($model['decimals'])));
            $add($tax, 'ram:TypeCode', 'VAT');
            if ($group['category'] !== 'S' && $group['category'] !== 'Z') {
                $add($tax, 'ram:ExemptionReason', $group['reason'] ?? 'Exempt from VAT');
            }
            $add($tax, 'ram:BasisAmount', $amount($group['net']->rounded($model['decimals'])));
            $add($tax, 'ram:CategoryCode', $group['category']);
            if ($group['category'] !== 'S' && $group['category'] !== 'Z' && $group['reasonCode'] !== null) {
                $add($tax, 'ram:ExemptionReasonCode', $group['reasonCode']);
            }
            $add($tax, 'ram:RateApplicablePercent', $group['percent']);
        }
        if ($model['paid']) {
            $add($add($settlement, 'ram:SpecifiedTradePaymentTerms'), 'ram:Description', 'Paid');
        }
        $sums = $add($settlement, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $add($sums, 'ram:LineTotalAmount', $amount($model['net']));
        $add($sums, 'ram:TaxBasisTotalAmount', $amount($model['net']));
        $add($sums, 'ram:TaxTotalAmount', $amount($model['tax']), 'currencyID', $currency);
        if ($model['taxCurrency'] !== null) {
            $add($sums, 'ram:TaxTotalAmount', $model['taxCurrency']['total'], 'currencyID', $model['taxCurrency']['currency']);
        }
        if (!$model['rounding']->isZero()) {
            $add($sums, 'ram:RoundingAmount', $amount($model['rounding']));
        }
        $add($sums, 'ram:GrandTotalAmount', $amount($model['gross']->add($model['rounding'])));
        $add($sums, 'ram:TotalPrepaidAmount', $amount($model['prepaid']));
        $add($sums, 'ram:DuePayableAmount', $amount($model['gross']->subtract($model['prepaid'])->add($model['rounding'])));
        if ($model['corrects'] !== null) {
            $add($add($settlement, 'ram:InvoiceReferencedDocument'), 'ram:IssuerAssignedID', $model['corrects']);
        }

        return (string) $dom->saveXML();
    }

    /**
     * The net price of one, to six decimals, so the quantity times it gives back the line's net amount.
     */
    private static function unitPrice(Money $net, int $quantity): string
    {
        $tenThousandths = (int) str_replace('.', '', $net->format(4));
        $micro = intdiv($tenThousandths * 200 + $quantity, 2 * $quantity);

        return rtrim(rtrim(\sprintf('%d.%06d', intdiv($micro, 1_000_000), $micro % 1_000_000), '0'), '.');
    }
}
