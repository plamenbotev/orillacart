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
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Application\ScriptTests;

/**
 * An extension's own tests, run against the shop with everything the `needs` ask for granted, whether or not the
 * extension is enabled: its records and field values are rolled back after each test, its HTTP calls answered
 * from the test's map, its jobs recorded. Files sent with the request stand in for the saved ones, so an editor
 * tests what is on screen.
 */
final readonly class PackageTests
{
    public function __construct(
        private PackageRepository $packages,
        private Scripts $scripts,
        private ScriptTests $tests,
        private ObjectStore $objects,
        private ObjectDefinitions $definitions,
    ) {}

    /**
     * @param array<string, string>|null $files path → content, instead of the saved files
     *
     * @return list<array{name: string, ok: bool, message: ?string, file: ?string, line: ?int, http: list<array{method: string, url: string, status: int}>, jobs: list<array{name: string, payload: mixed, at: ?string}>, used: array<string, int|float>}>
     */
    public function run(string $slug, ?array $files = null): array
    {
        $package = $this->packages->find($slug) ?? throw new PackageNotFound($slug);
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
        $grant = new Grant($slug, $manifest->needs(), $manifest->hosts(), $package->settings());
        $this->definitions->use($slug, $manifest);
        try {
            $results = $this->tests->run($source, $scope, $grant, fn(callable $one): TestResult => $this->objects->isolated($one));
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
            ];
        }, $results);
    }
}
