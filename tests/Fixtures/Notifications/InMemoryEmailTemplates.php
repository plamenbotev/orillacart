<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Notifications;

use OrillaCart\Notifications\Domain\EmailTemplate;
use OrillaCart\Notifications\Domain\EmailTemplateRepository;
use OrillaCart\Shared\View\EditedTemplates;

final class InMemoryEmailTemplates implements EmailTemplateRepository, EditedTemplates
{
    /** @var array<string, EmailTemplate> */
    private array $templates = [];

    public function edited(string $name): ?string
    {
        return ($this->templates[$name] ?? null)?->source();
    }

    public function find(string $name): ?EmailTemplate
    {
        return $this->templates[$name] ?? null;
    }

    public function all(): array
    {
        ksort($this->templates);

        return array_values($this->templates);
    }

    public function save(EmailTemplate $template): void
    {
        $this->templates[$template->name()] = $template;
    }

    public function remove(EmailTemplate $template): void
    {
        unset($this->templates[$template->name()]);
    }
}
