<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Notifications\Domain\EmailTemplate;
use OrillaCart\Notifications\Domain\EmailTemplateRepository;
use OrillaCart\Shared\View\EditedTemplates;

/**
 * The shop's email templates, read all at once the first time an email template is asked for: a handful of rows,
 * and none for the storefront's templates.
 */
final class DoctrineEmailTemplates implements EmailTemplateRepository, EditedTemplates
{
    /** @var array<string, array<string, string>>|null by name and locale */
    private ?array $sources = null;

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function edited(string $name, string $locale): ?string
    {
        if (!str_starts_with($name, 'emails/')) {
            return null;
        }
        if ($this->sources === null) {
            $this->sources = [];
            foreach ($this->all() as $template) {
                $this->sources[$template->name()][$template->locale()] = $template->source();
            }
        }

        return $this->sources[$name][$locale] ?? $this->sources[$name][EmailTemplate::EVERY_LANGUAGE] ?? null;
    }

    public function find(string $name, string $locale = EmailTemplate::EVERY_LANGUAGE): ?EmailTemplate
    {
        return $this->entityManager->find(EmailTemplate::class, ['name' => $name, 'locale' => $locale]);
    }

    public function all(): array
    {
        /** @var list<EmailTemplate> */
        return $this->entityManager->getRepository(EmailTemplate::class)->findBy([], ['name' => 'ASC', 'locale' => 'ASC']);
    }

    public function save(EmailTemplate $template): void
    {
        $this->entityManager->persist($template);
        $this->entityManager->flush();
        $this->sources = null;
    }

    public function remove(EmailTemplate $template): void
    {
        $this->entityManager->remove($template);
        $this->entityManager->flush();
        $this->sources = null;
    }
}
