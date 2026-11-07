<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An extension written in the shop's script language (design 56): its files, where it stands, the permissions a
 * manager granted it, its settings, and how it has been doing. It lives in the site's database, so it follows the
 * tenant; the compiled class is derived from its files and kept as a cache.
 */
#[ORM\Entity]
#[ORM\Table(name: 'packages')]
#[ORM\UniqueConstraint(name: 'package_slug', columns: ['slug'])]
class Package
{
    public const string SLUG_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_FILES = 50;
    /** Failures in a row after which the shop stops the extension. */
    public const int FAILURES_TO_STOP = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $slug;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(length: 40, options: ['default' => ''])]
    private string $version = '';

    #[ORM\Column(length: 20, enumType: PackageState::class)]
    private PackageState $state = PackageState::Draft;

    /** @var list<string> the permissions granted when enabled: `orders:read`, `http`, … */
    #[ORM\Column(type: 'json')]
    private array $granted = [];

    /** Of the files that ran when it was enabled; a change to the files is seen against it. */
    #[ORM\Column(length: 16, options: ['default' => ''])]
    private string $enabledChecksum = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $failures = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastProblem = null;

    /** @var array<string, string|bool> the values of its `settings` declaration, secrets sealed */
    #[ORM\Column(type: 'sealed_json')]
    private array $settings = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, PackageFile> */
    #[ORM\OneToMany(targetEntity: PackageFile::class, mappedBy: 'package', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['path' => 'ASC'])]
    private Collection $files;

    public function __construct(string $slug, string $name, \DateTimeImmutable $at)
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new PackageRuleViolation('An extension\'s id is up to 40 lowercase letters, digits and underscores, starting with a letter: "support_tickets".', 'orillacart_invalid_extension_id');
        }
        $this->slug = $slug;
        $this->rename($name);
        $this->createdAt = $at;
        $this->updatedAt = $at;
        $this->files = new ArrayCollection();
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The extension is not saved yet.');
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new PackageRuleViolation('An extension has a name of up to %d characters.', 'orillacart_invalid_extension_name', values: [self::MAX_NAME_LENGTH]);
        }
        $this->name = $name;
    }

    /** What the `extension` declaration says, read when checked or enabled. */
    public function describe(string $name, string $version, \DateTimeImmutable $at): void
    {
        if ($name !== '') {
            $this->rename($name);
        }
        $this->version = mb_substr(trim($version), 0, 40);
        $this->updatedAt = $at;
    }

    public function state(): PackageState
    {
        return $this->state;
    }

    /** @return list<string> */
    public function granted(): array
    {
        return $this->granted;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function lastProblem(): ?string
    {
        return $this->lastProblem;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<PackageFile> by path */
    public function files(): array
    {
        return $this->files->getValues();
    }

    public function file(string $path): ?PackageFile
    {
        foreach ($this->files as $file) {
            if ($file->path() === $path) {
                return $file;
            }
        }

        return null;
    }

    public function write(string $path, string $content, \DateTimeImmutable $at): PackageFile
    {
        self::checkPath($path);
        if (\strlen($content) > PackageFile::MAX_CONTENT_LENGTH) {
            throw new PackageRuleViolation('A file holds at most %d KB.', 'orillacart_extension_file_too_large', values: [intdiv(PackageFile::MAX_CONTENT_LENGTH, 1024)]);
        }
        $file = $this->file($path);
        if ($file === null) {
            if ($this->files->count() >= self::MAX_FILES) {
                throw new PackageRuleViolation('An extension has at most %d files.', 'orillacart_extension_too_many_files', values: [self::MAX_FILES]);
            }
            $file = new PackageFile($this, $path, $content, $at);
            $this->files->add($file);
        } else {
            $file->write($content, $at);
        }
        $this->updatedAt = $at;

        return $file;
    }

    public function removeFile(string $path, \DateTimeImmutable $at): void
    {
        $file = $this->file($path) ?? throw new PackageRuleViolation('The extension has no file "%s".', 'orillacart_extension_file_not_found', values: [$path]);
        $this->files->removeElement($file);
        $this->updatedAt = $at;
    }

    /** The scripts as one source, in path order, each marked so a finding names its file. */
    public function source(): string
    {
        $parts = [];
        foreach ($this->files as $file) {
            if ($file->isScript()) {
                $parts[] = $file->content();
            }
        }

        return implode("\n", $parts);
    }

    /** @return list<PackageFile> */
    public function scripts(): array
    {
        return array_values(array_filter($this->files(), static fn(PackageFile $file): bool => $file->isScript()));
    }

    /** Of the files as they are now: the compiled class is kept by it. */
    public function checksum(): string
    {
        return substr(hash('sha256', $this->source()), 0, 16);
    }

    /** Whether the files changed since it was enabled: it then runs the old files until enabled again. */
    public function isStale(): bool
    {
        return $this->state->runs() && $this->enabledChecksum !== $this->checksum();
    }

    /**
     * Switches it on with the permissions the manager grants; what it declared it needs must be among them.
     *
     * @param list<string> $declared what the scripts' `extension { needs }` asks for
     * @param list<string> $granted  what the manager grants
     */
    public function enable(array $declared, array $granted, \DateTimeImmutable $at): void
    {
        $missing = array_values(array_diff($declared, $granted));
        if ($missing !== []) {
            throw new PackageRuleViolation('The extension needs permissions it was not granted: %s.', 'orillacart_extension_permissions_missing', values: [implode(', ', $missing)]);
        }
        if ($this->scripts() === []) {
            throw new PackageRuleViolation('The extension has no script to run: add a file ending in .ocs.', 'orillacart_extension_no_script');
        }
        $this->granted = array_values(array_unique($granted));
        $this->state = PackageState::Enabled;
        $this->enabledChecksum = $this->checksum();
        $this->failures = 0;
        $this->lastProblem = null;
        $this->updatedAt = $at;
    }

    public function disable(\DateTimeImmutable $at): void
    {
        if ($this->state !== PackageState::Draft) {
            $this->state = PackageState::Disabled;
        }
        $this->updatedAt = $at;
    }

    /**
     * A run failed: counted; after FAILURES_TO_STOP in a row the extension stops.
     *
     * @return bool whether this failure stopped it
     */
    public function failed(string $problem, \DateTimeImmutable $at): bool
    {
        $this->lastProblem = mb_substr($problem, 0, 2000);
        $this->updatedAt = $at;
        if (!$this->state->runs()) {
            return false;
        }
        if (++$this->failures >= self::FAILURES_TO_STOP) {
            $this->state = PackageState::Stopped;

            return true;
        }

        return false;
    }

    /** A run went well: the failures in a row start over. */
    public function succeeded(): void
    {
        $this->failures = 0;
    }

    /** @return array<string, string|bool> */
    public function settings(): array
    {
        return $this->settings;
    }

    /** @param array<string, string|bool> $settings */
    public function changeSettings(array $settings, \DateTimeImmutable $at): void
    {
        $this->settings = $settings;
        $this->updatedAt = $at;
    }

    public static function checkPath(string $path): void
    {
        if (preg_match('#^(?:[a-z0-9_-]+/)*[a-z0-9_.-]+\.(ocs|html\.twig|md)$#i', $path) !== 1 || str_contains($path, '..') || \strlen($path) > PackageFile::MAX_PATH_LENGTH) {
            throw new PackageRuleViolation('A file is named like "main.ocs" or "templates/support.html.twig": letters, digits, dashes, underscores and dots, in folders, ending in .ocs, .html.twig or .md.', 'orillacart_invalid_extension_file');
        }
    }
}
