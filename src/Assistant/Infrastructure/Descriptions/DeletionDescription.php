<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\Descriptions;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\RouteTool;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Shared\Http\Routing\Tool;

/**
 * A deletion as what it deletes: the tool's own path read as the admin's screen reads it ("the carrier
 * Courier"), or, for something listed in another thing (a customer's address), that thing. The read goes
 * through the same checks as any call, and changes nothing. Tools another description knows are left to it.
 */
final readonly class DeletionDescription implements CallDescription
{
    /** What names a thing in the shop's answers, in this order. */
    private const array NAMES = ['name', 'title', 'label', 'code', 'number', 'email', 'file_name'];

    /** Described by others, with more than a name. */
    private const array ELSEWHERE = ['anonymise_order', 'delete_failed_jobs', 'remove_sample_data', 'reset_email_template'];

    public function __construct(private RouteReads $reads) {}

    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string
    {
        if ($tool->risk !== Tool::DELETING || \in_array($tool->name, self::ELSEWHERE, true)) {
            return null;
        }
        $route = $this->reads->tool($tool->name);
        if ($route === null) {
            return null;
        }
        $what = $this->name($route->path, $route, $arguments) ?? $this->listed($route, $arguments);
        $parent = $what === null ? $this->name(self::parent($route->path), $route, $arguments) : null;
        if ($what === null && $parent === null) {
            return null;
        }
        // "Delete tax group": the thing is the title's words after its verb, as the shop names its tools.
        $thing = mb_strtolower((string) preg_replace('/^\S+\s+/', '', $tool->title));
        if ($what === null) {
            return \sprintf('Removes the %s of %s for good.', $thing, $parent) . self::extra($tool->name);
        }

        return "Deletes the {$thing} {$what} for good." . self::extra($tool->name);
    }

    /**
     * What the shop's read of the path calls the thing; null where it has no read, or the read names nothing.
     *
     * @param array<string, mixed> $arguments
     */
    private function name(?string $path, RouteTool $deleting, array $arguments): ?string
    {
        $data = $path === null ? null : $this->reads->read($path, $deleting, $arguments);

        return $data === null ? null : self::named($data);
    }

    /**
     * The thing as its kind's list names it, for a kind with no read of its own: "/stockrooms" lists the stockroom.
     *
     * @param array<string, mixed> $arguments
     */
    private function listed(RouteTool $deleting, array $arguments): ?string
    {
        if (preg_match('#^(.*)/\{([^}]+)\}$#', $deleting->path, $found) !== 1) {
            return null;
        }
        $id = $arguments[$deleting->placeholders[$found[2]] ?? $found[2]] ?? null;
        $list = $this->reads->read($found[1], $deleting, $arguments);
        if ($list === null || !\is_scalar($id)) {
            return null;
        }
        // The rows, or the rows under the answer's one list ({"stockrooms": […]}).
        $rows = array_is_list($list) ? $list : (array_values(array_filter($list, static fn(mixed $value): bool => \is_array($value) && array_is_list($value)))[0] ?? []);
        foreach ($rows as $row) {
            if (\is_array($row) && \is_scalar($row['id'] ?? null) && (string) $row['id'] === (string) $id) {
                return self::named($row);
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $data
     */
    private static function named(array $data): ?string
    {
        foreach ([$data, ...array_filter($data, \is_array(...))] as $level) {
            foreach (self::NAMES as $key) {
                $value = $level[$key] ?? null;
                if ((\is_string($value) && trim($value) !== '') || \is_int($value)) {
                    // An order is known by its number and its customer.
                    return $key === 'number' ? "#{$value}" . (\is_string($level['email'] ?? null) ? " ({$level['email']})" : '') : '"' . $value . '"';
                }
            }
        }

        return null;
    }

    /** "/customers/{id}/addresses/{address}" → "/customers/{id}"; null for a path of one thing. */
    private static function parent(string $path): ?string
    {
        $parent = preg_replace('#/[^/]+/\{[^}]+\}$#', '', $path);

        return \is_string($parent) && $parent !== $path && str_contains($parent, '{') ? $parent : null;
    }

    private static function extra(string $tool): string
    {
        return match ($tool) {
            'delete_order' => "\nOnly an order cancelled or failed, never paid, can go: the shop refuses any other.",
            default => '',
        };
    }
}
