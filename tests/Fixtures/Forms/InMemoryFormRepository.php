<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Forms;

use OrillaCart\Forms\Domain\Form;
use OrillaCart\Forms\Domain\FormPlacement;
use OrillaCart\Forms\Domain\FormRepository;

final class InMemoryFormRepository implements FormRepository
{
    /** @var array<int, Form> */
    private array $forms = [];
    private int $next = 1;

    public function all(): array
    {
        $forms = array_values($this->forms);
        usort($forms, static fn(Form $a, Form $b): int => [$a->position(), $a->id()] <=> [$b->position(), $b->id()]);

        return $forms;
    }

    public function active(FormPlacement $placement): array
    {
        return array_values(array_filter($this->all(), static fn(Form $form): bool => $form->isActive() && $form->placement() === $placement));
    }

    public function find(int $id): ?Form
    {
        return $this->forms[$id] ?? null;
    }

    public function save(Form $form): void
    {
        $id = new \ReflectionProperty(Form::class, 'id');
        if ($id->getValue($form) === null) {
            $id->setValue($form, $this->next++);
        }
        $this->forms[$form->id()] = $form;
    }

    public function remove(Form $form): void
    {
        unset($this->forms[$form->id()]);
    }
}
