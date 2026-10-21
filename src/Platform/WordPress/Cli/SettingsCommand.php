<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Cli;

use OrillaCart\Settings\Application\SettingsArchive;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * The shop's settings as a file, to keep or to take to another site, and back.
 */
final readonly class SettingsCommand implements CliCommand
{
    public function __construct(private SettingsArchive $archive) {}

    public static function name(): string
    {
        return 'orillacart settings';
    }

    /**
     * Writes the shop's settings as JSON: what each settings screen saves, but this site's pages and folders,
     * and secrets such as passwords.
     *
     * ## OPTIONS
     *
     * [--file=<path>]
     * : Where to write them; the standard output when left out.
     *
     * ## EXAMPLES
     *
     *     wp orillacart settings export --file=shop-settings.json
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function export(array $args, array $options): void
    {
        $json = (string) wp_json_encode($this->archive->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!isset($options['file'])) {
            \WP_CLI::log($json);

            return;
        }
        if (file_put_contents($options['file'], $json . "\n") === false) {
            \WP_CLI::error("Cannot write {$options['file']}.");
        }
        \WP_CLI::success("Wrote the settings to {$options['file']}.");
    }

    /**
     * Takes the settings of a file `export` wrote, section by section; this site keeps its secrets. A section the
     * shop cannot use is left as it was, and named.
     *
     * ## OPTIONS
     *
     * <file>
     * : The file.
     *
     * [--yes]
     * : Do not ask first.
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function import(array $args, array $options): void
    {
        $path = $args[0] ?? '';
        $contents = is_readable($path) ? file_get_contents($path) : false;
        $file = \is_string($contents) ? json_decode($contents, true) : null;
        if (!\is_array($file)) {
            \WP_CLI::error("{$path} is not a JSON file of the shop's settings.");
        }
        \WP_CLI::confirm('Replace the shop\'s settings with those of the file?', $options);
        $result = $this->archive->import($file);
        foreach ($result['refused'] as $section => $reason) {
            \WP_CLI::log("Left as it was: {$section} ({$reason})");
        }
        if ($result['imported'] === []) {
            \WP_CLI::error('Nothing was imported.');
        }
        \WP_CLI::success('Imported: ' . implode(', ', $result['imported']) . '.');
    }
}
