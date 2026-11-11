<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use OrillaCart\Extension\Setting;
use OrillaCart\Scripting\Application\CompiledScript;

/**
 * Reads a compiled extension's declarations: what the `extension` block says, the `settings` it declares as the
 * add-on API's Setting objects, and the declarations of each kind for the areas that register them.
 */
final readonly class PackageManifest
{
    /**
     * @param list<array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}> $declarations
     */
    public function __construct(private array $declarations) {}

    /**
     * @param class-string<CompiledScript> $className
     */
    public static function ofClass(string $className): self
    {
        return new self($className::manifest());
    }

    public function name(): string
    {
        $name = $this->extension()['props']['name'] ?? '';

        return \is_string($name) ? $name : '';
    }

    public function version(): string
    {
        $version = $this->extension()['props']['version'] ?? '';

        return \is_string($version) ? $version : (is_numeric($version) ? (string) $version : '');
    }

    /**
     * `needs: { orders: read, customers: [read, write], http: ["api.example"], assets: serve }` → orders:read,
     * customers:read, customers:write, http, assets.
     *
     * @return list<string>
     */
    public function needs(): array
    {
        $needs = [];
        $declared = $this->extension()['props']['needs'] ?? [];
        foreach (\is_array($declared) ? $declared : [] as $area => $levels) {
            if (!\is_string($area)) {
                continue;
            }
            if ($area === 'http' || $area === 'templates' || $area === 'assets') {
                $needs[] = $area;
                continue;
            }
            foreach (\is_array($levels) && !isset($levels['$spec']) ? $levels : [$levels] as $level) {
                $word = \is_array($level) ? ($level['$spec'] ?? null) : $level;
                if (\is_string($word)) {
                    $needs[] = $area . ':' . $word;
                }
            }
        }

        return array_values(array_unique($needs));
    }

    /**
     * The hosts `needs.http` names.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $needs = $this->extension()['props']['needs'] ?? null;
        $hosts = \is_array($needs) ? ($needs['http'] ?? []) : [];

        return array_values(array_filter(\is_array($hosts) ? $hosts : [], \is_string(...)));
    }

    /**
     * The `settings` block(s) at the top level, as the add-on API declares them.
     *
     * @return list<Setting>
     */
    public function settings(): array
    {
        $settings = [];
        foreach ($this->ofKind('settings') as $block) {
            $settings = [...$settings, ...self::settingsOf($block['props'])];
        }

        return $settings;
    }

    /**
     * Settings as a `settings` block or a declaration's `settings` prop declares them: key → type { label, … }.
     *
     * @param array<mixed> $props
     *
     * @return list<Setting>
     */
    public static function settingsOf(array $props): array
    {
        $settings = [];
        foreach ($props as $key => $spec) {
            if (!\is_array($spec) || !\is_string($spec['$spec'] ?? null)) {
                continue;
            }
            $type = $spec['$spec'];
            if (!\in_array($type, [Setting::TEXT, Setting::SECRET, Setting::BOOLEAN, Setting::NUMBER, Setting::CHOICE], true)) {
                continue;
            }
            $choices = [];
            foreach (\is_array($spec['of'] ?? null) ? $spec['of'] : [] as $value => $label) {
                if (!\is_string($label) && !is_numeric($label)) {
                    continue;
                }
                $choices[\is_int($value) ? (string) $label : $value] = (string) $label;
            }
            // A secret's `goes_with` names the settings that say where it goes; without one, every setting does.
            $goesWith = $type === Setting::SECRET && \is_array($spec['goes_with'] ?? null) ? array_values(array_filter($spec['goes_with'], static fn(mixed $other): bool => \is_string($other) && $other !== (string) $key && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $other) === 1)) : null;
            $settings[] = new Setting(
                (string) $key,
                \is_string($spec['label'] ?? null) ? $spec['label'] : ucfirst(str_replace('_', ' ', (string) $key)),
                $type,
                ($spec['required'] ?? false) === true,
                \is_string($spec['help'] ?? null) ? $spec['help'] : '',
                $choices,
                $goesWith,
            );
        }

        return $settings;
    }

    /**
     * @return list<array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}>
     */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->declarations, static fn(array $declaration): bool => $declaration['kind'] === $kind));
    }

    /**
     * @return list<array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}>
     */
    public function all(): array
    {
        return $this->declarations;
    }

    /**
     * @return array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}|null
     */
    private function extension(): ?array
    {
        return $this->ofKind('extension')[0] ?? null;
    }
}
