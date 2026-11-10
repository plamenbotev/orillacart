<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\Doctrine;

use OrillaCart\Packages\Application\PackageTemplates;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageState;

/** Reads the templates from the enabled packages' files, once per request; only names ending in .html.twig. */
final class DoctrinePackageTemplates implements PackageTemplates
{
    /** @var array<string, array{string, string}|null> the source and the extension it is from, by name */
    private array $found = [];

    public function __construct(private readonly PackageRepository $packages) {}

    public function source(string $name): ?string
    {
        return $this->found($name)[0] ?? null;
    }

    public function owner(string $name): ?string
    {
        return $this->found($name)[1] ?? null;
    }

    /** @return array{string, string}|null */
    private function found(string $name): ?array
    {
        if (!\array_key_exists($name, $this->found)) {
            $this->found[$name] = $this->find($name);
        }

        return $this->found[$name];
    }

    /** @return array{string, string}|null */
    private function find(string $name): ?array
    {
        if (!str_ends_with($name, '.html.twig') || $name !== strtolower($name)) {
            return null;
        }
        if (str_starts_with($name, self::PREFIX)) {
            $parts = explode('/', substr($name, \strlen(self::PREFIX)), 2);
            if (\count($parts) !== 2) {
                return null;
            }
            [$slug, $path] = $parts;
            $package = $this->packages->find($slug);

            $content = $package !== null && $package->state() === PackageState::Enabled ? $package->runningFile('templates/' . $path) : null;

            return $content === null ? null : [$content, $slug];
        }
        // An override of one of the shop's templates: the first enabled extension granted `templates` that has it.
        foreach ($this->packages->enabled() as $package) {
            if (!\in_array('templates', $package->granted(), true)) {
                continue;
            }
            $content = $package->runningFile('templates/' . $name);
            if ($content !== null) {
                return [$content, $package->slug()];
            }
        }

        return null;
    }
}
