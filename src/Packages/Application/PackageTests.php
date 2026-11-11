<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Packages\Domain\PackageNotFound;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageRuleViolation;
use OrillaCart\Scripting\Application\Dto\Finding;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Dto\TestResult;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Application\ScriptTests;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Security\ReadOnlyRequest;

/**
 * An extension's own tests, run against the shop whether or not the extension is enabled. The shop's own functions
 * hold to what a manager granted when enabling it (nothing, for one never enabled or since disabled); what the
 * files declare beyond that reaches only the stand-ins, which reach no one. A test reads only the settings it
 * gives itself, never the stored ones, so no stored secret can be read or carried out by a test. Its records and field values are rolled back after each test, its HTTP calls answered
 * from the test's map, its jobs recorded, its mail held and its orders placed as test orders (never sent, never
 * invoiced), so files sent with the request reach no one. Files sent with the request stand in for the saved ones, so an editor
 * tests what is on screen. On a request that may only read (a read-only key), files cannot be sent, and every
 * function that writes refuses in the tests too, as in the extension's runs: the HTTP answers and the jobs, which
 * only record, aside.
 */
final readonly class PackageTests
{
    public function __construct(
        private PackageRepository $packages,
        private Scripts $scripts,
        private ScriptTests $tests,
        private ObjectStore $objects,
        private ObjectDefinitions $definitions,
        private ?ReadOnlyRequest $readOnly = null,
        private ?RolledBackCaches $caches = null,
    ) {}

    /**
     * @param array<string, string>|null $files path → content, instead of the saved files
     *
     * @return list<array{name: string, ok: bool, message: ?string, file: ?string, line: ?int, http: list<array{method: string, url: string, status: int}>, jobs: list<array{name: string, payload: mixed, at: ?string}>, used: array<string, int|float>, effects: list<array{function: string, arguments: list<mixed>}>}>
     */
    public function run(string $slug, ?array $files = null): array
    {
        $package = $this->packages->find($slug) ?? throw new PackageNotFound($slug);
        $readOnly = $this->readOnly?->readOnly() === true;
        if ($readOnly && $files !== null) {
            throw new HttpException(403, 'orillacart_read_only_key', 'This API key only reads: it tests the saved files, not files sent with the request.');
        }
        $scripts = [];
        if ($files === null) {
            foreach ($package->scripts() as $file) {
                $scripts[$file->path()] = $file->content();
            }
        } else {
            foreach ($files as $path => $content) {
                if (str_ends_with($path, '.ocs')) {
                    $scripts[$path] = $content;
                }
            }
        }
        $source = implode("\n", $scripts);
        $scope = $files === null ? $slug : 'test/' . $slug;
        $compiled = $this->scripts->compile($source, $scope);
        if (!$compiled->ok()) {
            $findings = array_map(static fn(array $f): string => \sprintf('%s%s (line %d, column %d)', $f['file'] === '' ? '' : $f['file'] . ': ', $f['message'], $f['line'], $f['column']), PackageService::locate($scripts, $compiled->findings));

            throw new PackageRuleViolation('The extension does not compile: %s', 'orillacart_extension_does_not_compile', values: [implode('; ', $findings)]);
        }
        $manifest = PackageManifest::ofClass($this->scripts->load($source, $scope)::class);
        $grant = new Grant($slug, array_values(array_intersect($manifest->needs(), $package->granted())), $manifest->hosts());
        $this->definitions->use($slug, $manifest);
        try {
            $narrow = $readOnly ? static fn(HostCatalog $host): HostCatalog => ScriptRunner::readingOnly($host, ScriptTests::RECORDED) : null;
            $objects = $this->objects;
            // Once the shop's database refused a write, it takes no more in this request: the tests after are not run.
            $isolate = new class ($objects, $this->caches) {
                private ?string $broken = null;

                public function __construct(private readonly ObjectStore $objects, private readonly ?RolledBackCaches $caches) {}

                /** @param callable(): TestResult $one */
                public function __invoke(callable $one, string $name): TestResult
                {
                    if ($this->broken !== null) {
                        return new TestResult($name, false, \sprintf('Not run: the shop\'s database refused a write in "%s", and nothing more can be written until the tests run again.', $this->broken), null, [], [], []);
                    }
                    $result = $this->caches === null ? $this->objects->isolated($one) : $this->caches->around(fn(): TestResult => $this->objects->isolated($one));
                    if (!$this->objects->intact()) {
                        $this->broken = $result->name;
                    }

                    return $result;
                }
            };
            $results = $this->tests->run($source, $scope, $grant, $isolate, $narrow, $manifest->needs());
        } finally {
            $this->definitions->forget($slug);
        }

        return array_map(static function (TestResult $result) use ($scripts): array {
            $placed = $result->line === null ? null : (PackageService::locate($scripts, [new Finding($result->line, 1, '')])[0] ?? null);

            return [
                'name' => $result->name,
                'ok' => $result->ok,
                'message' => $result->message,
                'file' => $placed === null ? null : $placed['file'],
                'line' => $placed === null ? null : $placed['line'],
                'http' => $result->http,
                'jobs' => $result->jobs,
                'used' => $result->used,
                'effects' => $result->effects,
            ];
        }, $results);
    }
}
