<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The files of an extension as they stood after one save: kept on every write, delete, import and restore, so a
 * manager can read what changed and go back to any of them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'package_versions')]
#[ORM\UniqueConstraint(name: 'package_version_number', columns: ['package_id', 'number'])]
class PackageVersion
{
    public const int KEPT = 30;
    public const int MAX_NOTE_LENGTH = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, string> $files path → content
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Package::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Package $package,
        #[ORM\Column]
        private int $number,
        #[ORM\Column(length: self::MAX_NOTE_LENGTH)]
        private string $note,
        #[ORM\Column(type: 'json')]
        private array $files,
        #[ORM\Column(length: 16)]
        private string $checksum,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {}

    public function package(): Package
    {
        return $this->package;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function note(): string
    {
        return $this->note;
    }

    /** @return array<string, string> path → content */
    public function files(): array
    {
        return $this->files;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
