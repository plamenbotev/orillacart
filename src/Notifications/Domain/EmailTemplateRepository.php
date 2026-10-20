<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

interface EmailTemplateRepository
{
    public function find(string $name): ?EmailTemplate;

    /** @return list<EmailTemplate> */
    public function all(): array;

    public function save(EmailTemplate $template): void;

    public function remove(EmailTemplate $template): void;
}
