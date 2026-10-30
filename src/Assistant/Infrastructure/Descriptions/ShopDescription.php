<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\Descriptions;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;

/**
 * What changes for the whole shop: opening it, an "opening soon" page, settings with what each was and becomes, a
 * settings file taken in, the sample data and failed jobs cleared, an email template set back.
 */
final readonly class ShopDescription implements CallDescription
{
    public function __construct(private RouteReads $reads) {}

    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string
    {
        return match ($tool->name) {
            'open_shop' => 'Opens the shop: visitors can buy, and the "opening soon" page is gone.',
            'show_opening_soon' => 'Closes the shop behind an "opening soon" page: visitors see it instead of the shop, and cannot buy. You and other managers still see the shop.',
            'update_settings' => $this->settings($tool, $arguments),
            'import_settings' => $this->archive($arguments),
            'remove_sample_data' => 'Deletes the sample products, categories, customers and orders the shop added, for good; what you made yourself stays.',
            'delete_failed_jobs' => $this->failedJobs($tool, $arguments),
            'reset_email_template' => $this->template($tool, $arguments),
            default => null,
        };
    }

    /**
     * Each setting given that changes, from what it is to what it becomes.
     *
     * @param array<string, mixed> $arguments
     */
    private function settings(ToolDefinition $tool, array $arguments): ?string
    {
        $route = $this->reads->tool($tool->name);
        $now = $route === null ? null : $this->reads->read($route->path, $route, $arguments);
        if ($now === null) {
            return null;
        }
        $lines = [];
        foreach ($arguments as $name => $value) {
            if (\array_key_exists($name, $now) && $now[$name] !== $value) {
                $lines[] = '• ' . str_replace('_', ' ', $name) . ': ' . self::value($now[$name]) . ' → ' . self::value($value);
            }
        }
        if ($lines === []) {
            return 'Changes no setting: each is as given already.';
        }
        if (\array_key_exists('catalog_only', $arguments) && $arguments['catalog_only'] === true && ($now['catalog_only'] ?? false) !== true) {
            $lines[] = 'With the catalog only, visitors can no longer buy.';
        }

        return "Changes the shop's settings:\n" . implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function archive(array $arguments): string
    {
        $file = $arguments['settings_file'] ?? null;
        $sections = \is_array($file) ? array_keys(array_filter($file, \is_array(...))) : [];

        return 'Replaces the shop\'s settings with those in the file' . ($sections === [] ? '.' : ', section by section: ' . implode(', ', array_map(static fn(int|string $section): string => str_replace('_', ' ', (string) $section), $sections)) . '.')
            . "\nWhat the file does not hold stays as it is.";
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function failedJobs(ToolDefinition $tool, array $arguments): string
    {
        $route = $this->reads->tool('list_failed_jobs');
        $listed = $route === null ? null : $this->reads->read($route->path, $route, []);
        $jobs = \is_array($listed['jobs'] ?? null) ? $listed['jobs'] : [];
        $ids = \is_array($arguments['ids'] ?? null) ? array_map(strval(...), array_filter($arguments['ids'], is_scalar(...))) : [];
        $chosen = Words::yes($arguments, 'all') ? $jobs : array_filter($jobs, static fn(mixed $job): bool => \is_array($job) && \is_scalar($job['id'] ?? null) && \in_array((string) $job['id'], $ids, true));
        $lines = ['Deletes ' . (Words::yes($arguments, 'all') ? 'every failed job' : \count($chosen) . ' failed jobs') . ' for good: they will not be tried again.'];
        foreach (\array_slice(array_values($chosen), 0, 10) as $job) {
            if (\is_array($job) && \is_string($job['about'] ?? null)) {
                $lines[] = '• ' . $job['about'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function template(ToolDefinition $tool, array $arguments): string
    {
        $locale = Words::string($arguments, 'locale');

        return 'Sets the "' . str_replace('_', ' ', Words::string($arguments, 'name')) . '" email back to the shop\'s own template' . ($locale === '' ? '' : " in {$locale}") . ': your changes to it are lost.';
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'none',
            \is_bool($value) => $value ? 'yes' : 'no',
            \is_array($value) => $value === [] ? 'none' : implode(', ', array_map(static fn(mixed $item): string => \is_scalar($item) ? (string) $item : '…', $value)),
            \is_scalar($value) => (string) $value,
            default => '…',
        };
    }
}
