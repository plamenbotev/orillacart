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
    /**
     * What a package's file may be named, in lowercase: scripts, templates and notes anywhere; scripts,
     * stylesheets and SVGs for the browser under assets/ (design 59). An asset's name is allowed, not screened:
     * letters, digits, `-` and `_`, then only `.min` or numbers (`app.min.js`, `lib-3.7.1.min.js`) before its kind,
     * so no part of it is one a web server set up with `AddHandler` would run (`x.php.js`).
     */
    public const string PATH = '#^(?:(?:[a-z0-9_-]+/)*[a-z0-9_.-]+\\.(ocs|html\\.twig|md)|assets/(?:[a-z0-9_-]+/)*[a-z0-9_-]+(?:\\.(?:min|[0-9]+))*\\.(js|css|svg))$#D';
    /** What an asset under assets/ may be named, without the folder. */
    public const string ASSET = '#^(?:[a-z0-9_-]+/)*[a-z0-9_-]+(?:\\.(?:min|[0-9]+))*\\.(js|css|svg)$#D';
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

    /**
     * The files as they stood when it was enabled: what runs until it is enabled again, so an edit, an import or a
     * restore changes nothing a manager has not approved. Null for one enabled before the shop kept them.
     *
     * A list, not a map: a JSON object's keys come back in the database's order, and the scripts' order is the
     * collection's (by path) when it was enabled.
     *
     * @var list<array{0: string, 1: string}>|null [path, content] pairs
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $enabledFiles = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $failures = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastProblem = null;

    /** @var array<string, string|bool> the values of its `settings` declaration, secrets sealed */
    #[ORM\Column(type: 'sealed_json')]
    private array $settings = [];

    /** @var array<string, string> when each `every` schedule last ran, by its key, as ISO 8601 */
    #[ORM\Column(type: 'json')]
    private array $scheduleRuns = [];

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
        // By path, byte for byte: the order in memory is the order written, the database's is its collation's,
        // and the scripts' source, checksum and enabled snapshot must not depend on either.
        $files = $this->files->getValues();
        usort($files, static fn(PackageFile $a, PackageFile $b): int => strcmp($a->path(), $b->path()));

        return $files;
    }

    public function file(string $path): ?PackageFile
    {
        foreach ($this->files() as $file) {
            if ($file->path() === $path) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param string|null $originalHash the hash of the shop's template this file overrides, when it does
     */
    public function write(string $path, string $content, \DateTimeImmutable $at, ?string $originalHash = null): PackageFile
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
            $file = new PackageFile($this, $path, $content, $at, $originalHash);
            $this->files->add($file);
        } else {
            $file->write($content, $at, $originalHash);
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
        foreach ($this->files() as $file) {
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

    /**
     * The files it runs: as they stood when it was enabled while it is, and while the shop holds it stopped (its
     * routes still answer, that it is stopped); the files as they are otherwise.
     *
     * @return array<string, string> path → content
     */
    public function running(): array
    {
        $files = [];
        if (($this->state->runs() || $this->state === PackageState::Stopped) && $this->enabledFiles !== null) {
            foreach ($this->enabledFiles as [$path, $content]) {
                $files[$path] = $content;
            }

            return $files;
        }
        foreach ($this->files() as $file) {
            $files[$file->path()] = $file->content();
        }

        return $files;
    }

    /** The scripts it runs, as one source in the order source() has them. */
    public function runningSource(): string
    {
        return implode("\n", array_values(array_filter($this->running(), static fn(string $path): bool => str_ends_with($path, '.ocs'), \ARRAY_FILTER_USE_KEY)));
    }

    /** One file as it runs: a template or an asset. */
    public function runningFile(string $path): ?string
    {
        return $this->running()[$path] ?? null;
    }

    /**
     * The files a browser runs as the page it is on: every file under templates/ and every Twig file (their text
     * is HTML, `|raw` prints as it is) and every file under assets/. Whoever enables an extension that has them
     * publishes HTML as whoever views the page, administrators included.
     *
     * @return list<string> paths
     */
    public function browserFiles(): array
    {
        $paths = [];
        foreach ($this->files() as $file) {
            $path = strtolower($file->path());
            if (str_starts_with($path, 'templates/') || str_contains($path, '.twig') || str_starts_with($path, 'assets/')) {
                $paths[] = $file->path();
            }
        }

        return $paths;
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
        $this->enabledFiles = [];
        foreach ($this->files() as $file) {
            $this->enabledFiles[] = [$file->path(), $file->content()];
        }
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

    /** Whether a schedule is due, and if so, counts this as its run. */
    public function scheduleDue(string $key, int $seconds, \DateTimeImmutable $now): bool
    {
        $last = $this->scheduleRuns[$key] ?? null;
        if ($last !== null && $now->getTimestamp() - (int) strtotime($last) < $seconds - 60) {
            return false;
        }
        $this->scheduleRuns[$key] = $now->format(\DATE_ATOM);

        return true;
    }

    public static function checkPath(string $path): void
    {
        if (preg_match(self::PATH, $path) !== 1 || str_contains($path, '..') || \strlen($path) > PackageFile::MAX_PATH_LENGTH) {
            throw new PackageRuleViolation('A file is named like "main.ocs", "templates/support.html.twig" or "assets/portal.js": lowercase letters, digits, dashes, underscores and dots, in folders, ending in .ocs, .html.twig or .md; a script, a stylesheet or an SVG (.js, .css, .svg) under assets/, with no other dot in its name.', 'orillacart_invalid_extension_file');
        }
    }
}
