<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Checkout\FieldContext;
use OrillaCart\Extension\FailsClosed;
use OrillaCart\Forms\Application\Dto\AskedField;
use OrillaCart\Forms\Application\Dto\GivenAnswer;
use OrillaCart\Forms\Application\FieldAnswers;
use OrillaCart\Forms\Application\ShopForms;
use OrillaCart\Ordering\Application\Dto\OrderFieldView;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * The questions checkout asks besides the shop's own: add-ons' fields, then the shop's checkout forms for the
 * products in the cart. Which the form shows, and the answers an order keeps. A shipping question is asked only of
 * an order that ships.
 */
final readonly class CheckoutFields
{
    /** What a ticked box is kept as. */
    public const string YES = FieldAnswers::YES;

    public function __construct(
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
        private ExtensionFailures $failures,
        private ShopForms $forms,
        private FieldAnswers $answers,
    ) {}

    /**
     * @param list<ItemQuote> $items what the order holds
     *
     * @return list<AskedField>
     */
    public function asked(array $items, bool $ships): array
    {
        return array_map(static fn(array $field): AskedField => $field[0], $this->fields($items, $ships));
    }

    /**
     * Checks the answers and gives them as the order keeps them: a choice by its label, a ticked box as "Yes"; a
     * question left unanswered is not kept.
     *
     * @param array<array-key, mixed> $answers by field id, as the form sent them
     * @param list<ItemQuote>         $items   what the order holds
     *
     * @return list<OrderFieldView>
     *
     * @throws InvalidFields with each refused answer next to its field, as fields.vat_number
     */
    public function answers(array $answers, FieldContext $context, array $items, bool $ships): array
    {
        $addOns = [];
        $fields = [];
        foreach ($this->fields($items, $ships) as [$asked, $field]) {
            $fields[] = $asked;
            if ($field !== null) {
                $addOns[$asked->id] = $field;
            }
        }
        $given = $this->answers->check($fields, $answers, 'fields.', check: fn(AskedField $asked, string $answer): ?string => isset($addOns[$asked->id]) ? $this->check($addOns[$asked->id], $answer, $context) : null);

        return array_map(static fn(GivenAnswer $answer): OrderFieldView => new OrderFieldView($answer->id, $answer->label, $answer->answer), $given);
    }

    /**
     * Each field as asked, with the add-on's own when it is one.
     *
     * @param list<ItemQuote> $items
     *
     * @return list<array{AskedField, ?CheckoutField}>
     */
    private function fields(array $items, bool $ships): array
    {
        $fields = [];
        foreach ($this->extensions->checkoutFields() as $field) {
            $fields[] = [new AskedField(
                $field->id,
                $field->label,
                $field->kind,
                $field->required,
                $field->help,
                longest: $field->longest,
                choices: array_map(static fn(string $value, string $label): array => ['value' => $value, 'label' => $label, 'price' => null], array_map('strval', array_keys($field->choices)), array_values($field->choices)),
                section: $field->section,
            ), $field];
        }
        $products = array_map(static fn(ItemQuote $item): array => ['product_id' => $item->productId, 'category_ids' => $item->categoryIds], $items);
        foreach ($this->forms->checkoutFields($products) as $field) {
            $fields[] = [$field, null];
        }

        return array_values(array_filter($fields, static fn(array $field): bool => $ships || $field[0]->section !== CheckoutField::SHIPPING));
    }

    /**
     * What the add-on's own check says. One that fails is logged and the answer taken, noted on the order; one that
     * fails closed refuses the answer for now.
     */
    private function check(CheckoutField $field, string $answer, FieldContext $context): ?string
    {
        if ($field->check === null) {
            return null;
        }
        try {
            return $field->check->check($answer, $context);
        } catch (\Throwable $e) {
            $this->logger->error('The check of the checkout field {field} failed: {message}', ['field' => $field->id, 'message' => $e->getMessage(), 'exception' => $e]);
            if ($field->check instanceof FailsClosed) {
                return 'This answer cannot be checked right now. Try again in a moment.';
            }
            $this->failures->record(\sprintf('The check of the checkout field "%s" failed; the answer was taken unchecked.', $field->label), false);

            return null;
        }
    }
}
