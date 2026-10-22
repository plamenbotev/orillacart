<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

interface FormRepository
{
    /**
     * @return list<Form> in their order
     */
    public function all(): array;

    /**
     * @return list<Form> those switched on, in their order
     */
    public function active(FormPlacement $placement): array;

    public function find(int $id): ?Form;

    public function save(Form $form): void;

    public function remove(Form $form): void;
}
