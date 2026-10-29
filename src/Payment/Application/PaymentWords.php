<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Translations\Application\ContentTranslations;

/**
 * A payment method's words as the shopper reads them (design 51): in the language shown when translated, else its
 * own. The fee without a label of its own is called by the method's name, in that language too.
 */
final readonly class PaymentWords
{
    public function __construct(private ContentTranslations $translations) {}

    /**
     * @param list<PaymentMethod> $methods
     *
     * @return array<string, array{name: string, instructions: string, receipt_note: string, fee_label: string}> by gateway
     */
    public function of(array $methods, ?string $locale = null): array
    {
        $ids = array_values(array_filter(array_map(static fn(PaymentMethod $method): ?int => $method->id(), $methods), static fn(?int $id): bool => $id !== null));
        $shown = $this->translations->shown(PaymentMethodTranslations::TYPE, $ids, $locale);
        $words = [];
        foreach ($methods as $method) {
            $fields = $shown[$method->id() ?? 0] ?? [];
            $name = $fields['name'] ?? $method->name();
            $words[$method->gateway()] = [
                'name' => $name,
                'instructions' => $fields['instructions'] ?? $method->instructions(),
                'receipt_note' => $fields['receipt_note'] ?? $method->receiptNote(),
                'fee_label' => $fields['fee_label'] ?? ($method->hasFeeLabel() ? $method->feeLabel() : $name),
            ];
        }

        return $words;
    }

    /**
     * @return array{name: string, instructions: string, receipt_note: string, fee_label: string}
     */
    public function one(PaymentMethod $method, ?string $locale = null): array
    {
        return $this->of([$method], $locale)[$method->gateway()];
    }
}
