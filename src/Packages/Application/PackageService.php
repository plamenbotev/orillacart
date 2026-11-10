<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Setting;
use OrillaCart\Packages\Application\Dto\ArchiveFile;
use OrillaCart\Packages\Application\Dto\ArchiveInstalled;
use OrillaCart\Packages\Application\Dto\EnablePackage;
use OrillaCart\Packages\Application\Dto\InstallPackage;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\Dto\PackageExport;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Packages\Application\Dto\PackageView;
use OrillaCart\Packages\Application\Dto\RunFunction;
use OrillaCart\Packages\Application\Dto\RunResult;
use OrillaCart\Packages\Application\Dto\SavePackageSettings;
use OrillaCart\Packages\Domain\ExtensionRun;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageFile;
use OrillaCart\Packages\Domain\PackageNotFound;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageRuleViolation;
use OrillaCart\Packages\Domain\PackageState;
use OrillaCart\Packages\Domain\PackageVersion;
use OrillaCart\Packages\Domain\PackageVersions;
use OrillaCart\Scripting\Application\CompiledScript;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Compiled;
use OrillaCart\Scripting\Application\Dto\Finding;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\Ast\Str;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\Catchable;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\Parser;
use OrillaCart\Scripting\Domain\SyntaxError;
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
        private ?PackageVersions $versions = null,
        private ?RunLog $runs = null,
        private ?PluginTemplates $templates = null,
        private ?PackageTranslations $translations = null,
        private ?ExtensionTranslations $words = null,
        private ?TemplateWords $templateWords = null,
        private ?PackageArchives $archives = null,
        private ?ExtensionAssets $assets = null,
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
            $package->write($path, $content, $this->clock->now(), $this->originalHash($path));
        }
        $this->describeFrom($package, $this->compile($package));
        $this->packages->save($package);
        $this->versions?->keep($package, 'Installed', $this->clock->now());
        $this->translations?->changed($package);

        return $this->view($package, $this->compile($package));
    }

    public function writeFile(string $slug, string $path, string $content): PackageView
    {
        $package = $this->get($slug);
        $before = $package->file($path)?->content();
        $saved = $package->write($path, $content, $this->clock->now(), $this->originalHash($path));
        $compiled = $this->compile($package);
        $this->describeFrom($package, $compiled);
        $this->packages->save($package);
        if ($before !== $content) {
            $this->versions?->keep($package, ($before === null ? 'Added ' : 'Saved ') . $path, $this->clock->now());
            $this->translations?->changed($package);
        }

        // The file saved, not the whole extension again: the screen has the others.
        return $this->view($package, $compiled, [$saved->path()]);
    }

    public function deleteFile(string $slug, string $path): PackageView
    {
        $package = $this->get($slug);
        $package->removeFile($path, $this->clock->now());
        $this->packages->save($package);
        $this->versions?->keep($package, 'Deleted ' . $path, $this->clock->now());
        $this->translations?->changed($package);

        return $this->view($package, $this->compile($package), []);
    }

    /** The static check as it stands: the view with its findings. */
    public function check(string $slug): PackageView
    {
        $package = $this->get($slug);

        return $this->view($package, $this->compile($package), []);
    }

    /**
     * Switches the extension on: it must compile, and the manager's grant must cover what it declares it needs.
     * The compiled class is made now, so the first request that needs it finds it.
     */
    /**
     * @param bool $mayPublishHtml whether whoever enables it may publish HTML that runs as script for others
     *                             (WordPress's `unfiltered_html`): an extension with templates or browser files
     *                             does exactly that, so a shop manager without it drafts and tests such an
     *                             extension, and an administrator enables it
     */
    public function enable(string $slug, EnablePackage $command, bool $mayPublishHtml = false): PackageView
    {
        $package = $this->get($slug);
        $browser = $package->browserFiles();
        if ($browser !== [] && !$mayPublishHtml) {
            throw new PackageRuleViolation('The extension has templates or browser files (%s): they run as script for whoever views the page, so only a user who may publish raw HTML (an administrator; a super admin on a network) enables it.', 'orillacart_extension_needs_unfiltered_html', values: [implode(', ', \array_slice($browser, 0, 5))]);
        }
        $compiled = $this->compile($package);
        if (!$compiled->ok()) {
            throw new PackageRuleViolation('The extension does not compile: %s', 'orillacart_extension_does_not_compile', values: [implode('; ', $this->findings($package, $compiled))]);
        }
        $manifest = $this->manifest($package);
        $package->enable($manifest->needs(), $command->grant, $this->clock->now());
        $this->describeFrom($package, $compiled);
        $this->packages->save($package);
        $this->logger->info('The extension "{extension}" was enabled with the permissions {granted}.', ['extension' => $slug, 'granted' => $package->granted() === [] ? 'none' : implode(', ', $package->granted())]);

        return $this->view($package, $compiled, []);
    }

    public function disable(string $slug): PackageView
    {
        $package = $this->get($slug);
        $package->disable($this->clock->now());
        $this->packages->save($package);

        return $this->view($package, $this->compile($package), []);
    }

    /** Removes the extension, its files and its records; its compiled class is left to the cache's next clearing. */
    public function uninstall(string $slug): void
    {
        $package = $this->get($slug);
        $this->objects?->forgetExtension($slug);
        $this->runs?->forgetExtension($slug);
        $this->versions?->forget($package);
        $this->translations?->forget($package);
        $this->assets?->forget($slug);
        $this->packages->remove($package);
        $this->logger->info('The extension "{extension}" was uninstalled.', ['extension' => $slug]);
    }

    /**
     * @throws PackageRuleViolation for a setting the extension does not declare, or a required one left empty
     */
    public function saveSettings(string $slug, SavePackageSettings $command): PackageView
    {
        $package = $this->get($slug);
        [$fields, $running] = $this->settingFields($package) ?? [[], []];
        $values = SettingValues::accept($fields, $package->settings(), $command->settings, $package->name());
        $missing = SettingValues::missing($running, $values);
        if ($missing !== null) {
            throw new PackageRuleViolation('"%s" is required.', 'orillacart_extension_setting_required', values: [$missing->label]);
        }
        $package->changeSettings($values, $this->clock->now());
        $this->packages->save($package);

        return $this->view($package, $this->compile($package), []);
    }

    /**
     * The console: runs one top-level function with the default budget. An enabled (or stopped) extension runs the
     * files a manager approved when enabling it, with what was granted then; a draft or a disabled one runs its
     * files as saved with nothing granted (no permissions, no hosts, no settings), so an edit reaches nothing
     * until a manager approves it by enabling. What it returns, or how it failed, and what the run used.
     */
    public function run(string $slug, RunFunction $command): RunResult
    {
        $package = $this->get($slug);
        $source = self::approved($package) ? $package->runningSource() : $package->source();
        $compiled = $this->scripts->compile($source, $package->slug());
        if (!$compiled->ok()) {
            throw new PackageRuleViolation('The extension does not compile: %s', 'orillacart_extension_does_not_compile', values: [implode('; ', $this->findings($package, $compiled))]);
        }
        $script = $this->scripts->load($source, $package->slug(), new Budget(), $this->grantOf($package));
        $method = 'fn_' . $command->function;
        if (!method_exists($script, $method)) {
            throw new PackageRuleViolation('The extension has no top-level function "%s".', 'orillacart_extension_no_function', values: [$command->function]);
        }
        $what = 'console: ' . $command->function;
        try {
            $result = $script->run($method, ...array_values($command->arguments));
            $this->runs?->record($slug, $what, ExtensionRun::OK, null, $this->used($script), $this->clock->now());

            return new RunResult(true, Ops::plain($result), null, null, $this->used($script));
        } catch (Catchable $e) {
            $value = $e->value();
            $this->runs?->record($slug, $what, \is_string($value['kind'] ?? null) && \in_array($value['kind'], [ExtensionRun::REFUSED, ExtensionRun::UNAVAILABLE], true) ? $value['kind'] : ExtensionRun::ERROR, $e->getMessage(), $this->used($script), $this->clock->now());

            return new RunResult(false, null, $value, null, $this->used($script));
        } catch (BudgetExceeded $e) {
            $this->runs?->record($slug, $what, ExtensionRun::STOPPED, $e->getMessage(), $this->used($script), $this->clock->now());

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

        return new PackageExport($package->slug(), $package->name(), $package->version(), $files, translations: $this->translations?->export($package) ?? []);
    }

    /** An exported extension put in place: a new one, or the files of one with the same id replaced. */
    public function import(PackageExport $export): PackageView
    {
        $package = $this->packages->find($export->id);
        if ($package === null) {
            $installed = $this->install(new InstallPackage($export->id, $export->name, $export->files));
            $package = $this->packages->find($export->id);
            if ($package !== null && $this->translations !== null && $export->translations !== []) {
                $this->translations->import($package, $export->translations);
            }

            return $installed;
        }
        $now = $this->clock->now();
        foreach ($package->files() as $file) {
            if (!isset($export->files[$file->path()])) {
                $package->removeFile($file->path(), $now);
            }
        }
        foreach ($export->files as $path => $content) {
            $package->write($path, $content, $now, $this->originalHash($path));
        }
        $compiled = $this->compile($package);
        $this->describeFrom($package, $compiled);
        $this->packages->save($package);
        $this->versions?->keep($package, 'Imported', $now);
        $this->translations?->import($package, $export->translations);
        $this->translations?->changed($package);

        return $this->view($package, $compiled);
    }

    /**
     * Installs an extension from a zip of its files (design 58), as import() does: a new one as a draft, the files
     * of one with the same id replaced. Its id and name are extension.json's, else the `extension` block's.
     *
     * @throws PackageRuleViolation for a zip that is refused, or names no extension
     */
    public function installArchive(string $path): ArchiveInstalled
    {
        $archive = $this->archives()->read($path);
        $manifest = $archive->manifest ?? [];
        [$id, $name, $version] = [self::text($manifest['id'] ?? null), self::text($manifest['name'] ?? null), self::text($manifest['version'] ?? null)];
        if ($id === '') {
            [$id, $declaredName] = self::declared($archive->files);
            $name = $name !== '' ? $name : $declaredName;
        }
        if ($id === '') {
            throw new PackageRuleViolation('The zip names no extension: its scripts need an `extension "id" { name: "…" }` block, or the zip an extension.json with the id.', 'orillacart_extension_zip_unnamed');
        }
        $export = new PackageExport($id, $name !== '' ? $name : $id, $version, $archive->files, translations: self::translations($manifest['translations'] ?? []));

        return new ArchiveInstalled($this->import($export), $archive->skipped);
    }

    /** The extension as a zip: its files and an extension.json with its id, name, version and translations. */
    public function archive(string $slug): ArchiveFile
    {
        $export = $this->export($slug);
        $version = preg_replace('/[^A-Za-z0-9._-]+/', '-', $export->version) ?? '';

        return new ArchiveFile($export->id . ($version === '' ? '' : '-' . $version) . '.zip', base64_encode($this->archives()->write($export)));
    }

    private function archives(): PackageArchives
    {
        return $this->archives ?? throw new PackageRuleViolation('Extensions cannot be read from zips here.', 'orillacart_extension_zip_unsupported');
    }

    /**
     * The id and the name the scripts' `extension` block gives; empty when they do not parse or have none.
     *
     * @param array<string, string> $files
     *
     * @return array{string, string}
     */
    private static function declared(array $files): array
    {
        $scripts = array_filter($files, static fn(string $path): bool => str_ends_with($path, '.ocs'), \ARRAY_FILTER_USE_KEY);
        try {
            $program = Parser::parse(implode("\n", $scripts) . "\n");
        } catch (SyntaxError) {
            return ['', ''];
        }
        foreach ($program->declarations as $declaration) {
            if ($declaration->kind === 'extension' && $declaration->name !== null) {
                $name = $declaration->props['name'] ?? null;

                return [$declaration->name, $name instanceof Str ? $name->value : ''];
            }
        }

        return ['', ''];
    }

    /**
     * extension.json's translations as an import takes them: by word and locale, a string or a list of strings.
     *
     * @return array<string, array<string, list<string>|string>>
     */
    private static function translations(mixed $given): array
    {
        $translations = [];
        foreach (\is_array($given) ? $given : [] as $key => $byLocale) {
            if (!\is_string($key) || !\is_array($byLocale)) {
                continue;
            }
            foreach ($byLocale as $locale => $forms) {
                if (!\is_string($locale)) {
                    continue;
                }
                if (\is_string($forms)) {
                    $translations[$key][$locale] = $forms;
                } elseif (\is_array($forms)) {
                    $translations[$key][$locale] = array_values(array_map(static fn(mixed $form): string => \is_string($form) ? $form : '', $forms));
                }
            }
        }

        return $translations;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
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
                $script = $this->scripts->load($package->runningSource(), $package->slug(), new Budget(), $this->grantOf($package));
            } catch (\InvalidArgumentException $e) {
                $this->failed($package->slug(), $e->getMessage());
                continue;
            }
            $loaded[] = new LoadedPackage($package, $script::class, PackageManifest::ofClass($script::class), $this->words);
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

    /**
     * What a run of the extension is allowed: its granted permissions, the hosts the files it was enabled with
     * declared (a host added since waits for enabling again), its settings. One that is not enabled (a draft, a
     * disabled one) is allowed nothing: what its saved files declare was never approved.
     */
    public function grantOf(Package $package): Grant
    {
        if (!self::approved($package)) {
            return new Grant($package->slug());
        }
        $running = $package->runningSource();
        $hosts = $this->scripts->compile($running, $package->slug())->ok() ? PackageManifest::ofClass($this->scripts->load($running, $package->slug())::class)->hosts() : [];

        return new Grant($package->slug(), $package->granted(), $hosts, $package->settings());
    }

    private function get(string $slug): Package
    {
        return $this->packages->find($slug) ?? throw new PackageNotFound($slug);
    }

    /** Whether a manager approved the files it runs: enabled, or stopped after failing while enabled. */
    private static function approved(Package $package): bool
    {
        return $package->state()->runs() || $package->state() === PackageState::Stopped;
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

    /**
     * The settings a manager sets: those the files it runs declare, as they declare them, since what runs reads
     * them; then those only an edit since declares, to be set before it is enabled again. The required ones are
     * the running files'. For an extension that does not run, both are its files' as they are. Null when nothing
     * compiles.
     *
     * @param Compiled|null $compiled its files as they are, compiled
     *
     * @return array{list<Setting>, list<Setting>}|null every setting, and those of the files it runs
     */
    private function settingFields(Package $package, ?Compiled $compiled = null): ?array
    {
        $live = ($compiled ?? $this->compile($package))->ok() ? $this->manifest($package)->settings() : null;
        $source = $package->runningSource();
        if (!$package->state()->runs() || $source === $package->source() || !$this->scripts->compile($source, $package->slug())->ok()) {
            return $live === null ? null : [$live, $live];
        }
        $running = PackageManifest::ofClass($this->scripts->load($source, $package->slug())::class)->settings();
        $all = $running;
        $keys = array_map(static fn(Setting $setting): string => $setting->key, $running);
        foreach ($live ?? [] as $setting) {
            if (!\in_array($setting->key, $keys, true)) {
                $all[] = $setting;
            }
        }

        return [$all, $running];
    }

    /**
     * @param bool|list<string> $contents the files' contents: all, those of these paths, or none (the screen keeps
     *                                    what it read; a save sends back the file saved, not the whole extension)
     */
    private function view(Package $package, Compiled $compiled, bool|array $contents = true): PackageView
    {
        $settings = null;
        $needs = [];
        $routes = null;
        if ($compiled->ok()) {
            $manifest = $this->manifest($package);
            $needs = $manifest->needs();
            $routes = [];
            foreach ($manifest->ofKind('route') as $declaration) {
                $routes[] = ['method' => strtoupper($declaration['words'][0] ?? 'GET'), 'path' => '/' . trim((string) $declaration['name'], '/'), 'who' => \is_string($declaration['props']['who'] ?? null) ? $declaration['props']['who'] : 'manager'];
            }
        }
        $fields = $this->settingFields($package, $compiled);
        if ($fields !== null) {
            [$values, $secretsSet] = SettingValues::shown($fields[0], $package->settings());
            $shown = $this->words?->settings($package->slug(), ...$fields[0]) ?? $fields[0];
            $settings = array_map(static fn(Setting $setting): array => [
                'key' => $setting->key,
                'label' => $setting->label,
                'type' => $setting->type,
                'required' => $setting->required,
                'help' => $setting->help,
                'choices' => $setting->choices,
                'value' => $values[$setting->key] ?? null,
                'set' => \in_array($setting->key, $secretsSet, true),
            ], $shown);
        }

        return PackageView::fromPackage($package, $needs, $contents, $settings, $this->findings($package, $compiled), $this->currentHashes($package), $routes);
    }

    /**
     * The versions kept of an extension, newest first.
     *
     * @return list<array{number: int, note: string, created_at: string, checksum: string, files: list<string>, current: bool}>
     */
    public function versions(string $slug): array
    {
        $package = $this->get($slug);

        return array_map(static fn(PackageVersion $version): array => [
            'number' => $version->number(),
            'note' => $version->note(),
            'created_at' => $version->createdAt()->format(\DATE_ATOM),
            'checksum' => $version->checksum(),
            'files' => array_keys($version->files()),
            'current' => $version->checksum() === $package->checksum() && array_keys($version->files()) === array_map(static fn(PackageFile $f): string => $f->path(), $package->files()) && !array_any($package->files(), static fn(PackageFile $f): bool => ($version->files()[$f->path()] ?? null) !== $f->content()),
        ], $this->versions?->of($package) ?? []);
    }

    /** Puts the files back as a version had them, and keeps that as the newest version. */
    public function restore(string $slug, int $number): PackageView
    {
        $package = $this->get($slug);
        $version = $this->versions?->find($package, $number) ?? throw new PackageRuleViolation('The extension has no version %d.', 'orillacart_extension_version_not_found', values: [$number]);
        $now = $this->clock->now();
        foreach ($package->files() as $file) {
            if (!isset($version->files()[$file->path()])) {
                $package->removeFile($file->path(), $now);
            }
        }
        foreach ($version->files() as $path => $content) {
            $package->write($path, $content, $now, $this->originalHash($path));
        }
        $compiled = $this->compile($package);
        $this->describeFrom($package, $compiled);
        $this->packages->save($package);
        $this->versions->keep($package, \sprintf('Restored version %d', $number), $now);
        $this->translations?->changed($package);

        return $this->view($package, $compiled);
    }

    /**
     * The latest runs of an extension, newest first.
     *
     * @return list<array{at: string, what: string, outcome: string, message: ?string, used: array<string, int|float>}>
     */
    public function runs(string $slug, int $limit = 50): array
    {
        $this->get($slug);

        return array_map(static fn(ExtensionRun $run): array => [
            'at' => $run->ranAt()->format(\DATE_ATOM),
            'what' => $run->what(),
            'outcome' => $run->outcome(),
            'message' => $run->message(),
            'used' => $run->used(),
        ], $this->runs?->recent($slug, $limit) ?? []);
    }

    /** The shop's own source of the template a file overrides, for the diff; null for a file that overrides nothing. */
    public function original(string $slug, string $path): ?string
    {
        $file = $this->get($slug)->file($path);
        $name = $file?->overrides();

        return $name === null ? null : $this->templates?->source($name);
    }

    /** The hash of the shop's template a path under templates/ would override, or null when the shop has none. */
    private function originalHash(string $path): ?string
    {
        if ($this->templates === null || !str_starts_with($path, 'templates/')) {
            return null;
        }
        $source = $this->templates->source(substr($path, \strlen('templates/')));

        return $source === null ? null : $this->templates::hash($source);
    }

    /** @return array<string, string> */
    private function currentHashes(Package $package): array
    {
        $hashes = [];
        foreach ($package->files() as $file) {
            $name = $file->overrides();
            $source = $name === null ? null : $this->templates?->source($name);
            if ($source !== null && $this->templates !== null) {
                $hashes[$file->path()] = $this->templates::hash($source);
            }
        }

        return $hashes;
    }

    /**
     * The static check over files that are not saved anywhere: what an editor asks as its user types. The scripts
     * (`.ocs`) are compiled as one source in the order given, as a package's are; each finding names its file.
     *
     * @param array<string, string> $files path → content
     *
     * @return list<array{file: string, line: int, column: int, message: string}>
     */
    public function checkFiles(array $files, string $scope = 'draft'): array
    {
        $scripts = [];
        foreach ($files as $path => $content) {
            if (str_ends_with($path, '.ocs')) {
                $scripts[$path] = $content;
            }
        }
        $compiled = $this->scripts->compile(implode("\n", $scripts), 'check/' . $scope);

        return [...self::locate($scripts, $compiled->findings), ...$this->templateFindings($files, $scope)];
    }

    /**
     * What stops the templates among the files compiling, as the storefront compiles them.
     *
     * @param array<string, string> $files path → content
     *
     * @return list<array{file: string, line: int, column: int, message: string}>
     */
    private function templateFindings(array $files, string $extension): array
    {
        $findings = [];
        foreach ($files as $path => $content) {
            if (!str_ends_with($path, '.html.twig')) {
                continue;
            }
            $problem = $this->templateWords?->problem($extension, $path, $content);
            if ($problem !== null) {
                $findings[] = ['file' => $path, 'line' => $problem['line'], 'column' => 1, 'message' => $problem['message']];
                continue;
            }
            // asset("portal.js") names a file of the package (design 59): one it does not have prints nothing.
            foreach ($this->templateWords?->assets($path, $content) ?? [] as $asset) {
                if (!isset($files[ExtensionAssets::FOLDER . ltrim($asset['name'], '/')])) {
                    $findings[] = ['file' => $path, 'line' => $asset['line'], 'column' => 1, 'message' => \sprintf('asset("%s") names a file the extension does not have: add %s%s.', $asset['name'], ExtensionAssets::FOLDER, ltrim($asset['name'], '/'))];
                }
            }
        }

        return $findings;
    }

    /**
     * The findings with the file each is in: the scripts are compiled as one source, in path order.
     *
     * @return list<string>
     */
    private function findings(Package $package, Compiled $compiled): array
    {
        $scripts = [];
        foreach ($package->scripts() as $file) {
            $scripts[$file->path()] = $file->content();
        }

        $templates = [];
        foreach ($package->files() as $file) {
            $templates[$file->path()] = $file->content();
        }

        return array_map(
            static fn(array $finding): string => \sprintf('%s%s (line %d, column %d)', $finding['file'] === '' ? '' : $finding['file'] . ': ', $finding['message'], $finding['line'], $finding['column']),
            [...self::locate($scripts, $compiled->findings), ...$this->templateFindings($templates, $package->slug()), ...($compiled->ok() ? self::secretFindings($scripts, $this->manifest($package)->settings()) : [])],
        );
    }

    /**
     * A secret that names no `goes_with` goes with every other setting, so saving any of them (a tool changing
     * one number) forgets it until it is typed again: said where it is declared, for the author to name the
     * settings that say where it goes, or `[]` for one sent to the one host the needs name.
     *
     * @param array<string, string> $scripts path → content
     * @param list<Setting>         $settings
     *
     * @return list<array{file: string, line: int, column: int, message: string}>
     */
    private static function secretFindings(array $scripts, array $settings): array
    {
        if (\count($settings) < 2) {
            return [];
        }
        $findings = [];
        foreach ($settings as $setting) {
            if ($setting->type !== Setting::SECRET || $setting->goesWith !== null) {
                continue;
            }
            $file = '';
            $line = 1;
            foreach ($scripts as $path => $content) {
                if (preg_match('/^[ \t]*' . preg_quote($setting->key, '/') . '[ \t]*:[ \t]*secret\b/m', $content, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $file = $path;
                    $line = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;
                    break;
                }
            }
            $findings[] = ['file' => $file, 'line' => $line, 'column' => 1, 'message' => \sprintf('The secret "%s" names no goes_with, so it goes with every other setting: saving any of them forgets it until it is typed again. Name the settings that say where it goes or whose it is (goes_with: ["mode"]), or goes_with: [] for one sent only to the hosts the needs name', $setting->key)];
        }

        return $findings;
    }

    /**
     * Each finding placed in the file it is in, with the line counted from that file's start.
     *
     * @param array<string, string> $scripts path → content, in the order they were compiled
     * @param list<Finding>         $findings
     *
     * @return list<array{file: string, line: int, column: int, message: string}>
     */
    public static function locate(array $scripts, array $findings): array
    {
        $starts = [];
        $line = 1;
        foreach ($scripts as $path => $content) {
            $starts[] = [$line, $path];
            $line += substr_count($content, "\n") + 1;
        }

        return array_map(static function (Finding $finding) use ($starts): array {
            $file = '';
            $offset = 0;
            foreach ($starts as [$start, $path]) {
                if ($finding->line >= $start) {
                    $file = $path;
                    $offset = $start - 1;
                }
            }

            return ['file' => $file, 'line' => $finding->line - $offset, 'column' => $finding->column, 'message' => $finding->message];
        }, $findings);
    }

    /** @return array<string, int|float> */
    private function used(CompiledScript $script): array
    {
        $context = new \ReflectionProperty(CompiledScript::class, 'c')->getValue($script);

        return $context instanceof \OrillaCart\Scripting\Application\Context ? $context->used() : [];
    }
}
