<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Setting;
use OrillaCart\Packages\Application\Dto\EnablePackage;
use OrillaCart\Packages\Application\Dto\InstallPackage;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\Dto\PackageExport;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Packages\Application\Dto\PackageView;
use OrillaCart\Packages\Application\Dto\RunFunction;
use OrillaCart\Packages\Application\Dto\RunResult;
use OrillaCart\Packages\Application\Dto\SavePackageSettings;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageNotFound;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageRuleViolation;
use OrillaCart\Packages\Domain\PackageState;
use OrillaCart\Scripting\Application\CompiledScript;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Compiled;
use OrillaCart\Scripting\Application\Dto\Finding;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\Catchable;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\Extension\SettingValues;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Extensions written in the script language: installing, writing their files, checking, enabling with granted
 * permissions, disabling, uninstalling, their settings, the console, and what happens when one keeps failing.
 */
final readonly class PackageService
{
    public function __construct(
        private PackageRepository $packages,
        private Scripts $scripts,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ?ObjectStore $objects = null,
    ) {}

    /** @return list<PackageView> */
    public function all(): array
    {
        return array_map(fn(Package $package): PackageView => PackageView::fromPackage($package, $this->needsOf($package)), $this->packages->all());
    }

    public function package(string $slug): PackageView
    {
        $package = $this->get($slug);

        return $this->view($package, $this->compile($package));
    }

    public function install(InstallPackage $command): PackageView
    {
        if ($this->packages->find($command->id) !== null) {
            throw new PackageRuleViolation('There is an extension "%s" already.', 'orillacart_extension_exists', values: [$command->id]);
        }
        $package = new Package($command->id, $command->name, $this->clock->now());
        foreach ($command->files as $path => $content) {
            $package->write($path, $content, $this->clock->now());
        }
        $this->describeFrom($package, $this->compile($package));
        $this->packages->save($package);

        return $this->view($package, $this->compile($package));
    }

    public function writeFile(string $slug, string $path, string $content): PackageView
    {
        $package = $this->get($slug);
        $package->write($path, $content, $this->clock->now());
        $compiled = $this->compile($package);
        $this->describeFrom($package, $compiled);
        $this->packages->save($package);

        return $this->view($package, $compiled);
    }

    public function deleteFile(string $slug, string $path): PackageView
    {
        $package = $this->get($slug);
        $package->removeFile($path, $this->clock->now());
        $this->packages->save($package);

        return $this->view($package, $this->compile($package));
    }

    /** The static check as it stands: the view with its findings. */
    public function check(string $slug): PackageView
    {
        $package = $this->get($slug);

        return $this->view($package, $this->compile($package));
    }

    /**
     * Switches the extension on: it must compile, and the manager's grant must cover what it declares it needs.
     * The compiled class is made now, so the first request that needs it finds it.
     */
    public function enable(string $slug, EnablePackage $command): PackageView
    {
        $package = $this->get($slug);
        $compiled = $this->compile($package);
        if (!$compiled->ok()) {
            throw new PackageRuleViolation('The extension does not compile: %s', 'orillacart_extension_does_not_compile', values: [implode('; ', $this->findings($package, $compiled))]);
        }
        $manifest = $this->manifest($package);
        $package->enable($manifest->needs(), $command->grant, $this->clock->now());
        $this->describeFrom($package, $compiled);
        $this->packages->save($package);
        $this->logger->info('The extension "{extension}" was enabled with the permissions {granted}.', ['extension' => $slug, 'granted' => $package->granted() === [] ? 'none' : implode(', ', $package->granted())]);

        return $this->view($package, $compiled);
    }

    public function disable(string $slug): PackageView
    {
        $package = $this->get($slug);
        $package->disable($this->clock->now());
        $this->packages->save($package);

        return $this->view($package, $this->compile($package));
    }

    /** Removes the extension, its files and its records; its compiled class is left to the cache's next clearing. */
    public function uninstall(string $slug): void
    {
        $package = $this->get($slug);
        $this->objects?->forgetExtension($slug);
        $this->packages->remove($package);
        $this->logger->info('The extension "{extension}" was uninstalled.', ['extension' => $slug]);
    }

    /**
     * @throws PackageRuleViolation for a setting the extension does not declare, or a required one left empty
     */
    public function saveSettings(string $slug, SavePackageSettings $command): PackageView
    {
        $package = $this->get($slug);
        $fields = $this->manifest($package)->settings();
        $values = SettingValues::accept($fields, $package->settings(), $command->settings, $package->name());
        $missing = SettingValues::missing($fields, $values);
        if ($missing !== null) {
            throw new PackageRuleViolation('"%s" is required.', 'orillacart_extension_setting_required', values: [$missing->label]);
        }
        $package->changeSettings($values, $this->clock->now());
        $this->packages->save($package);

        return $this->view($package, $this->compile($package));
    }

    /**
     * The console: runs one top-level function with the extension's granted permissions and the default budget.
     * What it returns, or how it failed, and what the run used.
     */
    public function run(string $slug, RunFunction $command): RunResult
    {
        $package = $this->get($slug);
        $compiled = $this->compile($package);
        if (!$compiled->ok()) {
            throw new PackageRuleViolation('The extension does not compile: %s', 'orillacart_extension_does_not_compile', values: [implode('; ', $this->findings($package, $compiled))]);
        }
        $script = $this->scripts->load($package->source(), $package->slug(), new Budget(), $this->grantOf($package));
        $method = 'fn_' . $command->function;
        if (!method_exists($script, $method)) {
            throw new PackageRuleViolation('The extension has no top-level function "%s".', 'orillacart_extension_no_function', values: [$command->function]);
        }
        try {
            $result = $script->run($method, ...array_values($command->arguments));

            return new RunResult(true, Ops::plain($result), null, null, $this->used($script));
        } catch (Catchable $e) {
            return new RunResult(false, null, $e->value(), null, $this->used($script));
        } catch (BudgetExceeded $e) {
            return new RunResult(false, null, null, $e->getMessage(), $this->used($script));
        }
    }

    public function export(string $slug): PackageExport
    {
        $package = $this->get($slug);
        $files = [];
        foreach ($package->files() as $file) {
            $files[$file->path()] = $file->content();
        }

        return new PackageExport($package->slug(), $package->name(), $package->version(), $files);
    }

    /** An exported extension put in place: a new one, or the files of one with the same id replaced. */
    public function import(PackageExport $export): PackageView
    {
        $package = $this->packages->find($export->id);
        if ($package === null) {
            return $this->install(new InstallPackage($export->id, $export->name, $export->files));
        }
        $now = $this->clock->now();
        foreach ($package->files() as $file) {
            if (!isset($export->files[$file->path()])) {
                $package->removeFile($file->path(), $now);
            }
        }
        foreach ($export->files as $path => $content) {
            $package->write($path, $content, $now);
        }
        $compiled = $this->compile($package);
        $this->describeFrom($package, $compiled);
        $this->packages->save($package);

        return $this->view($package, $compiled);
    }

    /**
     * The extensions that run, each with its compiled class, and those in the state asked for besides (the stopped
     * ones, for the routes that answer 503); one that no longer compiles (a host function went) is counted as
     * failed and left out.
     *
     * @return list<LoadedPackage>
     */
    public function loaded(?PackageState $also = null): array
    {
        $loaded = [];
        $packages = $this->packages->enabled();
        if ($also !== null) {
            $packages = [...$packages, ...array_values(array_filter($this->packages->all(), static fn(Package $package): bool => $package->state() === $also))];
        }
        foreach ($packages as $package) {
            try {
                $script = $this->scripts->load($package->source(), $package->slug(), new Budget(), $this->grantOf($package));
            } catch (\InvalidArgumentException $e) {
                $this->failed($package->slug(), $e->getMessage());
                continue;
            }
            $loaded[] = new LoadedPackage($package, $script::class, PackageManifest::ofClass($script::class));
        }

        return $loaded;
    }

    /** A run of the extension failed in the shop: logged, counted, and after enough in a row the extension stops. */
    public function failed(string $slug, string $problem): void
    {
        $package = $this->packages->find($slug);
        if ($package === null) {
            return;
        }
        $stopped = $package->failed($problem, $this->clock->now());
        $this->packages->save($package);
        if ($stopped) {
            $this->logger->error('The extension "{extension}" failed {count} times in a row and was stopped: {problem}', ['extension' => $slug, 'count' => Package::FAILURES_TO_STOP, 'problem' => $problem]);
        } else {
            $this->logger->warning('The extension "{extension}" failed: {problem}', ['extension' => $slug, 'problem' => $problem]);
        }
    }

    public function succeeded(string $slug): void
    {
        $package = $this->packages->find($slug);
        if ($package !== null && $package->failures() > 0) {
            $package->succeeded();
            $this->packages->save($package);
        }
    }

    /** Whether an extension's schedule is due now; the run is recorded when it is. */
    public function scheduleDue(string $slug, string $key, int $seconds, \DateTimeImmutable $now): bool
    {
        $package = $this->packages->find($slug);
        if ($package === null || !$package->scheduleDue($key, $seconds, $now)) {
            return false;
        }
        $this->packages->save($package);

        return true;
    }

    /** How many extensions the shop stopped: for the Home screen. */
    public function stopped(): int
    {
        return \count(array_filter($this->packages->all(), static fn(Package $package): bool => $package->state() === PackageState::Stopped));
    }

    /** What a run of the extension is allowed: its granted permissions, the hosts it declared, its settings. */
    public function grantOf(Package $package): Grant
    {
        $hosts = $this->compile($package)->ok() ? $this->manifest($package)->hosts() : [];

        return new Grant($package->slug(), $package->granted(), $hosts, $package->settings());
    }

    private function get(string $slug): Package
    {
        return $this->packages->find($slug) ?? throw new PackageNotFound($slug);
    }

    private function compile(Package $package): Compiled
    {
        return $this->scripts->compile($package->source(), $package->slug());
    }

    private function manifest(Package $package): PackageManifest
    {
        $script = $this->scripts->load($package->source(), $package->slug());

        return PackageManifest::ofClass($script::class);
    }

    /** The `extension` block names and versions the package, when it compiles. */
    private function describeFrom(Package $package, Compiled $compiled): void
    {
        if (!$compiled->ok()) {
            return;
        }
        $manifest = $this->manifest($package);
        $package->describe($manifest->name(), $manifest->version(), $this->clock->now());
    }

    /** @return list<string> */
    private function needsOf(Package $package): array
    {
        return $this->compile($package)->ok() ? $this->manifest($package)->needs() : [];
    }

    private function view(Package $package, Compiled $compiled): PackageView
    {
        $settings = null;
        $needs = [];
        if ($compiled->ok()) {
            $manifest = $this->manifest($package);
            $needs = $manifest->needs();
            [$values, $secretsSet] = SettingValues::shown($manifest->settings(), $package->settings());
            $settings = array_map(static fn(Setting $setting): array => [
                'key' => $setting->key,
                'label' => $setting->label,
                'type' => $setting->type,
                'required' => $setting->required,
                'help' => $setting->help,
                'choices' => $setting->choices,
                'value' => $values[$setting->key] ?? null,
                'set' => \in_array($setting->key, $secretsSet, true),
            ], $manifest->settings());
        }

        return PackageView::fromPackage($package, $needs, true, $settings, $this->findings($package, $compiled));
    }

    /**
     * The findings with the file each is in: the scripts are compiled as one source, in path order.
     *
     * @return list<string>
     */
    private function findings(Package $package, Compiled $compiled): array
    {
        $starts = [];
        $line = 1;
        foreach ($package->scripts() as $file) {
            $starts[] = [$line, $file->path()];
            $line += substr_count($file->content(), "\n") + 1;
        }

        return array_map(static function (Finding $finding) use ($starts): string {
            $file = '';
            $offset = 0;
            foreach ($starts as [$start, $path]) {
                if ($finding->line >= $start) {
                    $file = $path;
                    $offset = $start - 1;
                }
            }

            return \sprintf('%s%s (line %d, column %d)', $file === '' ? '' : $file . ': ', $finding->message, $finding->line - $offset, $finding->column);
        }, $compiled->findings);
    }

    /** @return array<string, int|float> */
    private function used(CompiledScript $script): array
    {
        $context = new \ReflectionProperty(CompiledScript::class, 'c')->getValue($script);

        return $context instanceof \OrillaCart\Scripting\Application\Context ? $context->used() : [];
    }
}
