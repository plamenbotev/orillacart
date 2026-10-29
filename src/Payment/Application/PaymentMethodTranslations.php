<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Translations\Application\TranslatableType;

/** A payment method's name, instructions, receipt note and fee label in the site's other languages. */
final readonly class PaymentMethodTranslations implements TranslatableType
{
    public const string TYPE = 'payment_method';

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return [
            'name' => PaymentMethod::MAX_NAME_LENGTH,
            'instructions' => PaymentMethod::MAX_TEXT_LENGTH,
            'receipt_note' => PaymentMethod::MAX_TEXT_LENGTH,
            'fee_label' => PaymentMethod::MAX_NAME_LENGTH,
        ];
    }
}
