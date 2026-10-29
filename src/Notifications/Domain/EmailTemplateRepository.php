<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

interface EmailTemplateRepository
{
    public function find(string $name, string $locale = EmailTemplate::EVERY_LANGUAGE): ?EmailTemplate;

    /** @return list<EmailTemplate> by name, then locale */
    public function all(): array;

    public function save(EmailTemplate $template): void;

    public function remove(EmailTemplate $template): void;
}
