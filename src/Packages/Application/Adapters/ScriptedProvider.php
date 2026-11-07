<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Settings;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Packages\Application\ScriptRunner;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Shared\Extension\SettingValues;

/**
 * What the scripted providers share: the declaration they stand for, its settings, and running one of its
 * functions with the shop's records as script values.
 *
 * @phpstan-type Declaration array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}
 */
abstract class ScriptedProvider
{
    /**
     * @param Declaration $declaration
     */
    public function __construct(
        protected readonly LoadedPackage $loaded,
        protected readonly array $declaration,
        protected readonly ScriptRunner $runner,
    ) {}

    public function id(): string
    {
        return $this->declaration['name'] ?? $this->loaded->package->slug();
    }

    /** The declaration's kind: `payment_gateway`, `order_column`, … */
    public function kind(): string
    {
        return $this->declaration['kind'];
    }

    /** @return list<Setting> the `settings` block inside the declaration */
    public function settings(): array
    {
        /** @var list<array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}> $nested */
        $nested = $this->declaration['nested'];

        return new PackageManifest($nested)->settings();
    }

    /** What the shop calls the provider: its declared name, else the extension's. */
    protected function title(): string
    {
        $name = $this->declaration['props']['name'] ?? null;

        return \is_string($name) && $name !== '' ? $name : $this->loaded->package->name();
    }

    protected function has(string $function): bool
    {
        return ScriptRunner::has($this->declaration, $function);
    }

    /**
     * Runs the declaration's function; a script that says it cannot reach its provider, or broke, raises what the
     * shop expects of any provider.
     *
     * @param list<mixed> $args
     *
     * @throws ProviderUnavailable
     * @throws HandlerFailed for a `refused` failure, which each caller turns into its own answer
     */
    protected function call(string $function, array $args): mixed
    {
        try {
            return $this->runner->run($this->loaded, ScriptRunner::method($this->declaration, $function), $args);
        } catch (HandlerFailed $e) {
            if ($e->kind === HandlerFailed::REFUSED) {
                throw $e;
            }

            throw new ProviderUnavailable($this->title(), $e->getMessage(), $e);
        }
    }

    /**
     * What the function must have answered with.
     *
     * @param class-string $class
     */
    protected function expect(mixed $value, string $class, string $function): object
    {
        if (!$value instanceof $class) {
            throw new ProviderUnavailable($this->title(), \sprintf('"%s" answered %s where %s was expected.', $function, \OrillaCart\Scripting\Domain\Ops::kind($value), new \ReflectionClass($class)->getShortName()));
        }

        return $value;
    }

    /** A required setting left empty: the provider is not ready. */
    protected function missingSetting(Settings $settings): ?string
    {
        $missing = SettingValues::missing($this->settings(), $settings->all());

        return $missing === null ? null : \sprintf('Enter %s.', lcfirst($missing->label));
    }

    /** @return array<string, string|bool> */
    protected static function values(Settings $settings): array
    {
        return Values::settings($settings);
    }
}
