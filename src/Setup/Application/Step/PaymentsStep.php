<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Payment\Application\Dto\PaymentMethodView;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Application\PaymentMethodService;
use OrillaCart\Payment\Domain\Iban;
use OrillaCart\Setup\Application\AskedByCountry;
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
final readonly class PaymentsStep implements SetupStep, AskedByCountry
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
                replacesByDefault: true,
            );
        }
        $bank = $payments->bank;
        if ($payments->bankTransfer && $bank !== null) {
            $details = $this->details($bank);
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
        $account = $bank === null ? null : self::account($bank);
        $off = static fn(PaymentMethodView $method): Text => Text::of('%s is not offered.', $method->name);

        return [
            self::BANK_TRANSFER => $payments->bankTransfer
                ? [true, $bank === null || $account === null ? $this->methods->show(self::BANK_TRANSFER)->instructions : $this->translations->text($account['iban']
                    ? Text::of('Pay to %1$s, IBAN %2$s%3$s, and give your order number as the reference.', trim($bank->holder), $account['number'], $account['code'] === '' ? '' : ', BIC ' . $account['code'])
                    : ($account['code'] === ''
                        ? Text::of('Pay to %1$s, account %2$s, and give your order number as the reference.', trim($bank->holder), $account['number'])
                        : Text::of('Pay to %1$s, account %2$s (bank code %3$s), and give your order number as the reference.', trim($bank->holder), $account['number'], $account['code']))), [], Text::of('Customers can pay by bank transfer to %s.', $bank === null ? '—' : trim($bank->holder)), match (true) {
                            $bank === null => Text::of('Give the account customers pay into, or bank transfers go without one.'),
                            $account !== null && $account['iban'] && !$account['valid'] => Text::of('%s is not a valid IBAN: check it.', $account['number']),
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

    private function details(BankAccountAnswers $bank): string
    {
        $account = self::account($bank);

        return implode("\n", array_filter([
            trim($bank->holder),
            trim($bank->bankName),
            ($account['iban'] ? 'IBAN ' : '') . $account['number'],
            $account['code'] === '' ? '' : ($account['iban'] ? 'BIC ' : $this->translations->text(Text::of('Bank code')) . ' ') . $account['code'],
        ], static fn(string $line): bool => $line !== ''));
    }

    /**
     * The account as people read it: an IBAN in groups of four, checked by its digits; another number as given.
     *
     * @return array{iban: bool, valid: bool, number: string, code: string}
     */
    private static function account(BankAccountAnswers $bank): array
    {
        $iban = Iban::looksLikeOne($bank->account);
        $checked = $iban ? Iban::check($bank->account) : null;

        return [
            'iban' => $iban,
            'valid' => $checked !== null,
            'number' => $checked === null ? trim($bank->account) : Iban::grouped($checked),
            'code' => strtoupper(trim($bank->bankCode)),
        ];
    }
}
