<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application;

use OrillaCart\Forms\Application\Dto\AskedField;
use OrillaCart\Forms\Application\Dto\GivenAnswer;
use OrillaCart\Forms\Domain\FieldChoice;
use OrillaCart\Forms\Domain\Form;
use OrillaCart\Forms\Domain\FormField;
use OrillaCart\Forms\Domain\FormPlacement;
use OrillaCart\Forms\Domain\FormRepository;
use OrillaCart\Shared\Domain\InvalidFields;

/**
 * The shop's forms where they are asked: a product's questions on its page and in the cart, and checkout's for the
 * products in the cart.
 */
final readonly class ShopForms
{
    public function __construct(
        private FormRepository $forms,
        private FieldAnswers $answers,
    ) {}

    /**
     * @param list<int> $categoryIds the product's, each with those above it
     *
     * @return list<AskedField>
     */
    public function productFields(int $productId, array $categoryIds): array
    {
        return $this->asked(FormPlacement::Product, static fn(Form $form): bool => $form->isFor($productId, $categoryIds));
    }

    /**
     * Checks the answers for one item. From the shopper, files are named by their token; from the cart ($kept), by
     * the id the cart kept.
     *
     * @param list<int> $categoryIds
     *
     * @return list<GivenAnswer>
     *
     * @throws InvalidFields as personalisation.<field id>
     */
    public function productAnswers(int $productId, array $categoryIds, mixed $sent, bool $kept = false): array
    {
        return $this->answers->check($this->productFields($productId, $categoryIds), $sent, 'personalisation.', $kept);
    }

    /**
     * The questions checkout asks for these products: a form is asked once when the cart holds any product it is for.
     *
     * @param list<array{product_id: int, category_ids: list<int>}> $items
     *
     * @return list<AskedField>
     */
    public function checkoutFields(array $items): array
    {
        return $this->asked(FormPlacement::Checkout, static function (Form $form) use ($items): bool {
            foreach ($items as $item) {
                if ($form->isFor($item['product_id'], $item['category_ids'])) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param \Closure(Form): bool $for
     *
     * @return list<AskedField>
     */
    private function asked(FormPlacement $placement, \Closure $for): array
    {
        $fields = [];
        foreach ($this->forms->active($placement) as $form) {
            if ($for($form)) {
                array_push($fields, ...array_map(static fn(FormField $field): AskedField => self::field($form, $field), $form->fields()));
            }
        }

        return $fields;
    }

    private static function field(Form $form, FormField $field): AskedField
    {
        return new AskedField(
            $form->fieldId($field->key),
            $field->label,
            $field->kind->value,
            $field->required,
            $field->help,
            $field->placeholder,
            $field->longest,
            array_map(static fn(FieldChoice $choice): array => ['value' => $choice->value, 'label' => $choice->label, 'price' => $choice->price], $field->choices),
            $field->display,
            $field->price,
            $field->min,
            $field->max,
            $field->whole,
            $field->section->value,
            $field->condition === null ? null : ['field' => $form->fieldId($field->condition->field), 'values' => $field->condition->values],
            $field->types,
            $field->largest,
        );
    }
}
