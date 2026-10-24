<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An email template as the shop wrote it in the admin, in place of the theme's or the plugin's file of the name.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_templates')]
class EmailTemplate
{
    public const int MAX_LENGTH = 100000;

    #[ORM\Column(type: 'text')]
    private string $source;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?int $updatedBy;

    public function __construct(
        /** As the files name it: "emails/order-shipped.html.twig". */
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        private string $name,
        string $source,
        \DateTimeImmutable $at,
        ?int $by,
    ) {
        $this->source = '';
        $this->updatedAt = $at;
        $this->updatedBy = $by;
        $this->change($source, $at, $by);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updatedBy(): ?int
    {
        return $this->updatedBy;
    }

    public function change(string $source, \DateTimeImmutable $at, ?int $by): void
    {
        if (trim($source) === '' || \strlen($source) > self::MAX_LENGTH) {
            throw new EmailRuleViolation('A template has up to %d characters, and is not empty: reset it to go back to the file.', 'orillacart_invalid_template', values: [self::MAX_LENGTH]);
        }
        $this->source = $source;
        $this->updatedAt = $at;
        $this->updatedBy = $by;
    }
}
