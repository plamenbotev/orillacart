<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Payment\Application\Dto\PaymentMethodView;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Application\PaymentMethodService;
use OrillaCart\Payment\Domain\Iban;
use OrillaCart\Setup\Application\Dto\BankAccountAnswers;
use OrillaCart\Setup\Application\Dto\PaymentsAnswers;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\SetupAnswers;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\I18n\Translations;

/**
 * Payments that need no account elsewhere: bank transfer into the shop's account (which invoices give too), cash
 * on delivery in the shop's own country, cheques sent to the seller.
 */
final readonly class PaymentsStep implements SetupStep
{
    public const string KEY = 'payments';
    private const string BANK_TRANSFER = 'bank_transfer';
    private const string CASH_ON_DELIVERY = 'cash_on_delivery';
    private const string CHEQUE = 'cheque';
    private const string BANK_DETAILS = 'payments.bank_details';

    public function __construct(
        private PaymentMethodService $methods,
        private Invoices $invoices,
        private Translations $translations,
        private ShopFacts $facts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function position(): int
    {
        return 50;
    }

    public function answers(): string
    {
        return PaymentsAnswers::class;
    }

    public function plan(SetupAnswers $answers): array
    {
        $payments = $answers->of(self::KEY, PaymentsAnswers::class);
        if ($payments === null) {
            return [];
        }
        $changes = [];
        foreach ($this->wanted($payments, $answers) as $gateway => [$active, $instructions, $countries, $summary, $warning]) {
            $current = $this->methods->show($gateway);
            if ($current->active === $active && (!$active || ($current->instructions === $instructions && $current->countries === $countries))) {
                continue;
            }
            $changes[] = new PlannedChange(
                self::KEY . '.' . $gateway,
                self::KEY,
                $summary,
                $active && $current->instructions !== '' && $current->instructions !== $instructions ? Text::of('%1$s tells customers now: "%2$s"', $current->name, $current->instructions) : null,
                ['active' => $active, 'instructions' => $instructions, 'countries' => $countries],
                $warning,
            );
        }
        $bank = $payments->bank;
        if ($payments->bankTransfer && $bank !== null) {
            $details = self::details($bank);
            if ($this->invoices->settings()->bankDetails !== $details) {
                $changes[] = new PlannedChange(self::BANK_DETAILS, self::KEY, Text::of('Invoices give the bank account: %s.', str_replace("\n", ', ', $details)), data: ['details' => $details]);
            }
        }

        return $changes;
    }

    public function apply(PlannedChange $change, SetupAnswers $answers): void
    {
        if ($change->key === self::BANK_DETAILS) {
            $details = $change->data['details'] ?? '';
            $this->invoices->changeBankDetails(\is_string($details) ? $details : '');

            return;
        }
        $gateway = substr($change->key, \strlen(self::KEY) + 1);
        $current = $this->methods->show($gateway);
        $instructions = $change->data['instructions'] ?? '';
        $countries = $change->data['countries'] ?? [];
        $this->methods->update($gateway, new SavePaymentMethod(
            $current->name,
            ($change->data['active'] ?? false) === true,
            $current->position,
            \is_array($countries) ? array_values(array_filter($countries, \is_string(...))) : [],
            \is_string($instructions) ? $instructions : '',
            $current->receiptNote,
            [],
            $current->fee,
            $current->feePercent,
            $current->feeLabel,
        ));
    }

    /**
     * What each offline method should be: on or off, what it tells customers, where it is offered.
     *
     * @return array<string, array{bool, string, list<string>, Text, ?Text}>
     */
    private function wanted(PaymentsAnswers $payments, SetupAnswers $answers): array
    {
        $home = $this->facts->country($answers);
        [$seller, $address] = $this->facts->seller($answers);
        $bank = $payments->bank;
        $iban = $bank === null ? null : Iban::check($bank->iban);
        $off = static fn(PaymentMethodView $method): Text => Text::of('%s is not offered.', $method->name);

        return [
            self::BANK_TRANSFER => $payments->bankTransfer
                ? [true, $bank === null ? $this->methods->show(self::BANK_TRANSFER)->instructions : $this->translations->text(Text::of('Pay to %1$s, IBAN %2$s%3$s, and give your order number as the reference.', trim($bank->holder), $iban === null ? trim($bank->iban) : Iban::grouped($iban), $bank->bic === '' ? '' : ', BIC ' . strtoupper($bank->bic))), [], Text::of('Customers can pay by bank transfer to %s.', $bank === null ? '—' : trim($bank->holder)), match (true) {
                    $bank === null => Text::of('Give the account customers pay into, or bank transfers go without one.'),
                    $iban === null => Text::of('%s is not a valid IBAN: check it.', trim($bank->iban)),
                    default => null,
                }]
                : [false, '', [], $off($this->methods->show(self::BANK_TRANSFER)), null],
            self::CASH_ON_DELIVERY => $payments->cashOnDelivery
                ? [true, $this->methods->show(self::CASH_ON_DELIVERY)->instructions, $home === null ? [] : [$home], Text::of('Customers at home can pay cash on delivery.'), null]
                : [false, '', [], $off($this->methods->show(self::CASH_ON_DELIVERY)), null],
            self::CHEQUE => $payments->cheque
                ? [true, $this->translations->text(Text::of('Send your cheque to %1$s, %2$s, with your order number.', $seller, $address)), [], Text::of('Customers can pay by cheque, sent to %s.', $seller), $seller === '' ? Text::of('Cheques need the seller\'s name and address: give them in the first step.') : null]
                : [false, '', [], $off($this->methods->show(self::CHEQUE)), null],
        ];
    }

    private static function details(BankAccountAnswers $bank): string
    {
        $iban = Iban::check($bank->iban);

        return implode("\n", array_filter([trim($bank->holder), trim($bank->bankName), 'IBAN ' . ($iban === null ? trim($bank->iban) : Iban::grouped($iban)), $bank->bic === '' ? '' : 'BIC ' . strtoupper($bank->bic)], static fn(string $line): bool => $line !== ''));
    }
}
