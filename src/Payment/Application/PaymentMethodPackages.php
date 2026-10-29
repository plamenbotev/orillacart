<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackageSource;

/** A payment method's words for the translation service: its name, instructions, receipt note and fee's label. */
final readonly class PaymentMethodPackages implements TranslationPackageSource
{
    public const string KIND = 'payment_method';

    public function __construct(private PaymentMethodRepository $methods) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart payment method';
    }

    public function package(int $id): ?TranslationPackage
    {
        $method = $this->find($id);
        if ($method === null) {
            return null;
        }
        $type = PaymentMethodTranslations::TYPE;

        return TranslationPackage::of(self::KIND, $id, $method->name(), [
            new PackageString($type, $id, 'name', $method->name(), Text::of('Name')),
            new PackageString($type, $id, 'instructions', $method->instructions(), Text::of('Instructions'), true),
            new PackageString($type, $id, 'receipt_note', $method->receiptNote(), Text::of('Note on the receipt'), true),
            new PackageString($type, $id, 'fee_label', $method->hasFeeLabel() ? $method->feeLabel() : '', Text::of('Fee')),
        ]);
    }

    public function ids(): iterable
    {
        return array_values(array_filter(array_map(static fn(PaymentMethod $method): ?int => $method->id(), $this->methods->all()), \is_int(...)));
    }

    private function find(int $id): ?PaymentMethod
    {
        foreach ($this->methods->all() as $method) {
            if ($method->id() === $id) {
                return $method;
            }
        }

        return null;
    }
}
