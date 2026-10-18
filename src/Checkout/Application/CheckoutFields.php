<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\CheckoutFieldView;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Checkout\FieldContext;
use OrillaCart\Ordering\Application\Dto\OrderFieldView;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * The questions add-ons ask at checkout: which the form shows, and the answers an order keeps. A shipping
 * question is asked only of an order that ships.
 */
final readonly class CheckoutFields
{
    /** What a ticked box is kept as. */
    public const string YES = 'Yes';

    public function __construct(
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<CheckoutFieldView>
     */
    public function asked(bool $ships): array
    {
        return array_map(static fn(CheckoutField $field): CheckoutFieldView => new CheckoutFieldView(
            $field->id,
            $field->label,
            $field->kind,
            $field->section,
            $field->required,
            array_map(static fn(string $value, string $label): array => ['value' => $value, 'label' => $label], array_map('strval', array_keys($field->choices)), array_values($field->choices)),
            $field->help,
            $field->longest,
        ), $this->fields($ships));
    }

    /**
     * Checks the answers and gives them as the order keeps them: a choice by its label, a ticked box as "Yes"; a
     * question left unanswered is not kept.
     *
     * @param array<array-key, mixed> $answers by field id, as the form sent them
     *
     * @return list<OrderFieldView>
     *
     * @throws InvalidFields with each refused answer next to its field, as fields.vat_number
     */
    public function answers(array $answers, FieldContext $context, bool $ships): array
    {
        $kept = [];
        $violations = [];
        foreach ($this->fields($ships) as $field) {
            $answer = self::answer($field, $answers[$field->id] ?? null);
            $problem = match (true) {
                $answer === null => 'This answer is not one of the choices.',
                $answer === '' => $field->required ? ($field->kind === CheckoutField::CHECKBOX ? 'Tick this to order.' : 'This field is required.') : null,
                mb_strlen($answer) > $field->longest => \sprintf('Up to %d characters.', $field->longest),
                default => $this->check($field, $answer, $context),
            };
            if ($problem !== null) {
                $violations[] = ['field' => 'fields.' . $field->id, 'message' => $problem];
            } elseif ($answer !== null && $answer !== '') {
                $kept[] = new OrderFieldView($field->id, $field->label, $field->kind === CheckoutField::CHOICE ? $field->choices[$answer] : $answer);
            }
        }
        if ($violations !== []) {
            throw new InvalidFields($violations);
        }

        return $kept;
    }

    /**
     * @return list<CheckoutField>
     */
    private function fields(bool $ships): array
    {
        return array_values(array_filter($this->extensions->checkoutFields(), static fn(CheckoutField $field): bool => $ships || $field->section !== CheckoutField::SHIPPING));
    }

    /** The answer as text, '' for none; null for a choice the field does not offer. */
    private static function answer(CheckoutField $field, mixed $raw): ?string
    {
        if ($field->kind === CheckoutField::CHECKBOX) {
            return \in_array($raw, [true, 1, '1', 'yes', 'on', 'true'], true) ? self::YES : '';
        }
        $text = \is_string($raw) || \is_int($raw) || \is_float($raw) ? trim((string) $raw) : '';
        if ($field->kind === CheckoutField::CHOICE && $text !== '' && !\array_key_exists($text, $field->choices)) {
            return null;
        }

        return $text;
    }

    /** What the add-on's own check says; one that fails is logged and the answer taken. */
    private function check(CheckoutField $field, string $answer, FieldContext $context): ?string
    {
        if ($field->check === null) {
            return null;
        }
        try {
            return $field->check->check($answer, $context);
        } catch (\Throwable $e) {
            $this->logger->error('The check of the checkout field {field} failed: {message}', ['field' => $field->id, 'message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
    }
}
