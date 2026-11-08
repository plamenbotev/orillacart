<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

use Doctrine\ORM\Mapping as ORM;

/** One run of an extension's handler or console function: what ran, how it ended and what it used. */
#[ORM\Entity]
#[ORM\Table(name: 'extension_runs')]
#[ORM\Index(name: 'extension_run_recent', columns: ['extension', 'id'])]
#[ORM\Index(name: 'extension_run_at', columns: ['ran_at'])]
class ExtensionRun
{
    public const string OK = 'ok';
    public const string REFUSED = 'refused';
    public const string UNAVAILABLE = 'unavailable';
    public const string ERROR = 'error';
    public const string STOPPED = 'stopped';
    public const int MAX_WHAT_LENGTH = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, int|float> $used
     */
    public function __construct(
        #[ORM\Column(length: 40)]
        private string $extension,
        #[ORM\Column(length: self::MAX_WHAT_LENGTH)]
        private string $what,
        #[ORM\Column(length: 20)]
        private string $outcome,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $message,
        #[ORM\Column(type: 'json')]
        private array $used,
        #[ORM\Column]
        private \DateTimeImmutable $ranAt,
    ) {}

    public function id(): int
    {
        return $this->id ?? 0;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function what(): string
    {
        return $this->what;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    /** @return array<string, int|float> */
    public function used(): array
    {
        return $this->used;
    }

    public function ranAt(): \DateTimeImmutable
    {
        return $this->ranAt;
    }
}
