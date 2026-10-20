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
    /** @var array<string, string>|null */
    private ?array $sources = null;

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function edited(string $name): ?string
    {
        if (!str_starts_with($name, 'emails/')) {
            return null;
        }
        if ($this->sources === null) {
            $this->sources = [];
            foreach ($this->all() as $template) {
                $this->sources[$template->name()] = $template->source();
            }
        }

        return $this->sources[$name] ?? null;
    }

    public function find(string $name): ?EmailTemplate
    {
        return $this->entityManager->find(EmailTemplate::class, $name);
    }

    public function all(): array
    {
        /** @var list<EmailTemplate> */
        return $this->entityManager->getRepository(EmailTemplate::class)->findBy([], ['name' => 'ASC']);
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
