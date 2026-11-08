<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

use Doctrine\ORM\Mapping as ORM;

/** One file of an extension: a script (`.ocs`) or a template (`templates/….html.twig`). */
#[ORM\Entity]
#[ORM\Table(name: 'package_files')]
#[ORM\UniqueConstraint(name: 'package_file_path', columns: ['package_id', 'path'])]
class PackageFile
{
    public const int MAX_PATH_LENGTH = 200;
    public const int MAX_CONTENT_LENGTH = 512 * 1024;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** For a template that overrides one of the shop's: the hash of the original when this was saved. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $originalHash = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Package::class, inversedBy: 'files')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Package $package,
        #[ORM\Column(length: self::MAX_PATH_LENGTH)]
        private string $path,
        string $content,
        \DateTimeImmutable $at,
        ?string $originalHash = null,
    ) {
        $this->write($content, $at, $originalHash);
    }

    public function write(string $content, \DateTimeImmutable $at, ?string $originalHash = null): void
    {
        $this->content = $content;
        $this->updatedAt = $at;
        $this->originalHash = $originalHash;
    }

    public function originalHash(): ?string
    {
        return $this->originalHash;
    }

    /** The shop's template this file overrides, when it is under templates/ and not the extension's own. */
    public function overrides(): ?string
    {
        return $this->originalHash === null || !str_starts_with($this->path, 'templates/') ? null : substr($this->path, \strlen('templates/'));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** A script: compiled with the others. A template is read by its path. */
    public function isScript(): bool
    {
        return str_ends_with($this->path, '.ocs');
    }
}
