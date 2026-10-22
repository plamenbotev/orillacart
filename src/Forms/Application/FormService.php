<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application;

use OrillaCart\Forms\Application\Dto\FormView;
use OrillaCart\Forms\Application\Dto\SaveFieldChoice;
use OrillaCart\Forms\Application\Dto\SaveForm;
use OrillaCart\Forms\Application\Dto\SaveFormField;
use OrillaCart\Forms\Domain\FieldChoice;
use OrillaCart\Forms\Domain\FieldCondition;
use OrillaCart\Forms\Domain\FieldKind;
use OrillaCart\Forms\Domain\FieldSection;
use OrillaCart\Forms\Domain\Form;
use OrillaCart\Forms\Domain\FormField;
use OrillaCart\Forms\Domain\FormNotFound;
use OrillaCart\Forms\Domain\FormPlacement;
use OrillaCart\Forms\Domain\FormRepository;
use OrillaCart\Forms\Domain\FormRuleViolation;
use OrillaCart\Forms\Domain\FormScope;

/**
 * Use cases of the Shop → Forms screen.
 */
final readonly class FormService
{
    public function __construct(private FormRepository $forms) {}

    /**
     * @return list<FormView> in their order
     */
    public function forms(): array
    {
        return array_map(FormView::fromForm(...), $this->forms->all());
    }

    /**
     * @throws FormNotFound
     */
    public function form(int $id): FormView
    {
        return FormView::fromForm($this->get($id));
    }

    /**
     * @throws FormRuleViolation
     */
    public function create(SaveForm $command): FormView
    {
        $form = new Form($command->name, FormPlacement::from($command->placement));
        $this->apply($form, $command);
        $this->forms->save($form);

        return FormView::fromForm($form);
    }

    /**
     * @throws FormNotFound
     * @throws FormRuleViolation
     */
    public function update(int $id, SaveForm $command): FormView
    {
        $form = $this->get($id);
        if ($form->placement()->value !== $command->placement) {
            throw new FormRuleViolation('A form stays where it is asked; make another for the other place.', 'orillacart_invalid_form');
        }
        $this->apply($form, $command);
        $this->forms->save($form);

        return FormView::fromForm($form);
    }

    /**
     * @throws FormNotFound
     */
    public function delete(int $id): void
    {
        $this->forms->remove($this->get($id));
    }

    private function apply(Form $form, SaveForm $command): void
    {
        $form->rename($command->name);
        $form->activate($command->active);
        $form->moveTo($command->position);
        $form->aimAt(FormScope::from($command->scope), $command->categoryIds, $command->productIds);
        $keys = [];
        foreach ($command->fields as $field) {
            if ($field->key !== '') {
                $keys[$field->key] = true;
            }
        }
        $form->replaceFields(array_map(function (SaveFormField $field) use (&$keys): FormField {
            $key = $field->key;
            if ($key === '') {
                $key = self::unique(self::slug($field->label, '_', 30), $keys, '_');
                $keys[$key] = true;
            }
            $values = [];
            foreach ($field->choices as $choice) {
                if ($choice->value !== '') {
                    $values[$choice->value] = true;
                }
            }

            return new FormField(
                $key,
                trim($field->label),
                FieldKind::from($field->kind),
                $field->required,
                trim($field->help),
                trim($field->placeholder),
                $field->longest,
                array_map(static function (SaveFieldChoice $choice) use (&$values): FieldChoice {
                    $value = $choice->value;
                    if ($value === '') {
                        $value = self::unique(self::slug($choice->label, '-', 40), $values, '-');
                        $values[$value] = true;
                    }

                    return new FieldChoice($value, trim($choice->label), self::amount($choice->price));
                }, $field->choices),
                $field->display,
                self::amount($field->price),
                $field->min,
                $field->max,
                $field->whole,
                FieldSection::from($field->section),
                $field->condition === null ? null : new FieldCondition($field->condition->field, array_values(array_unique($field->condition->values))),
                array_values(array_unique($field->types)),
                $field->largest,
            );
        }, $command->fields));
    }

    private function get(int $id): Form
    {
        return $this->forms->find($id) ?? throw new FormNotFound($id);
    }

    /** No price for an empty one or zero: nothing to add. */
    private static function amount(?string $price): ?string
    {
        return $price === null || $price === '' || (float) $price === 0.0 ? null : $price;
    }

    /** Lowercase ASCII from the label, transliterated where the site can: "Гравиране" makes "gravirane". */
    private static function slug(string $label, string $glue, int $longest): string
    {
        if (class_exists(\Transliterator::class)) {
            $latin = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($label);
            $label = \is_string($latin) ? $latin : $label;
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', $glue, strtolower($label)), $glue);
        if ($slug === '' || ($glue === '_' && ctype_digit($slug[0]))) {
            $slug = 'field' . ($slug === '' ? '' : $glue . $slug);
        }

        return rtrim(substr($slug, 0, $longest - 3), $glue);
    }

    /**
     * @param array<string, true> $taken
     */
    private static function unique(string $slug, array $taken, string $glue): string
    {
        $candidate = $slug;
        for ($n = 2; isset($taken[$candidate]); ++$n) {
            $candidate = $slug . $glue . $n;
        }

        return $candidate;
    }
}
