<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * `wp orillacart extension install <file.zip>`: installs an extension from a zip (design 58), a new one as a
 * draft, the files of one with the same id replaced. `wp orillacart extension export <id> [<file.zip>]`: writes
 * it as a zip, by default `<id>-<version>.zip` in the current folder. `wp orillacart extension list`: the site's
 * extensions with their state.
 */
final readonly class ExtensionCommand implements CliCommand
{
    public function __construct(private PackageService $packages) {}

    public static function name(): string
    {
        return 'orillacart extension';
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function install(array $args, array $options): void
    {
        $file = $args[0] ?? '';
        if ($file === '' || !is_file($file)) {
            \WP_CLI::error('Name the zip: wp orillacart extension install <file.zip>');
        }
        try {
            $installed = $this->packages->installArchive($file);
        } catch (RuleViolation $e) {
            \WP_CLI::error($e->getMessage());
        }
        foreach ($installed->skipped as $skipped) {
            \WP_CLI::warning(\sprintf('Left out %s: not an extension\'s file.', $skipped));
        }
        foreach ($installed->extension->findings ?? [] as $finding) {
            \WP_CLI::warning($finding);
        }
        \WP_CLI::success(\sprintf('Installed %s (%s), %s.', $installed->extension->name, $installed->extension->id, $installed->extension->state));
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function export(array $args, array $options): void
    {
        $id = $args[0] ?? '';
        if ($id === '') {
            \WP_CLI::error('Name the extension: wp orillacart extension export <id> [<file.zip>]');
        }
        try {
            $archive = $this->packages->archive($id);
        } catch (RuleViolation $e) {
            \WP_CLI::error($e->getMessage());
        }
        $to = $args[1] ?? $archive->filename;
        if (file_put_contents($to, (string) base64_decode($archive->content, true)) === false) {
            \WP_CLI::error(\sprintf('Could not write %s.', $to));
        }
        \WP_CLI::success(\sprintf('Wrote %s.', $to));
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function list(array $args, array $options): void
    {
        $rows = array_map(static fn($view): array => ['id' => $view->id, 'name' => $view->name, 'version' => $view->version, 'state' => $view->state], $this->packages->all());
        \WP_CLI\Utils\format_items('table', $rows, ['id', 'name', 'version', 'state']);
    }
}
