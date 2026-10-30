<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Assistant\Application\McpServer;
use OrillaCart\Assistant\Application\ToolSource;
use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Extension\Assistant\AssistantTool;
use OrillaCart\Extension\Assistant\ToolCall;
use OrillaCart\Extension\Assistant\ToolRefused;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Routing\Tool;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Add-ons' tools (extension API 15), under each add-on's name. One whose name or schema is not well formed, whose
 * name does not start with its add-on's prefix ("Parcel Co": parcelco_ or parcel_co_), or that takes the name of
 * any other tool (the routes', WordPress's, the setup's, the finder's) is left out and logged. A call that changes something is written into the
 * change log as the assistant's, as a route's would be. The tools are checked once per request.
 */
final class AddOnToolSource implements ToolSource
{
    /** @var list<ToolDefinition>|null */
    private ?array $definitions = null;

    /** @var array<string, true>|null the other sources' names */
    private ?array $taken = null;

    /**
     * @param iterable<ToolSource> $others every other source of tools
     */
    public function __construct(
        private readonly InstalledExtensions $extensions,
        #[AutowireIterator(ToolSource::TAG)]
        private readonly iterable $others,
        private readonly ChangeLog $log,
        private readonly LoggerInterface $logger,
    ) {}

    public function tools(): array
    {
        if ($this->definitions === null) {
            $this->definitions = [];
            foreach ($this->extensions->assistantTools() as $tool) {
                $definition = $this->definition($tool);
                if ($definition !== null) {
                    $this->definitions[] = $definition;
                }
            }
        }

        return $this->definitions;
    }

    public function run(ToolDefinition $tool, array $arguments, Caller $caller): ToolOutcome
    {
        $addOnTool = null;
        foreach ($this->extensions->assistantTools() as $candidate) {
            if ($candidate->name() === $tool->name) {
                $addOnTool = $candidate;
            }
        }
        if ($addOnTool === null) {
            return ToolOutcome::refused("The shop no longer has the tool {$tool->name}.");
        }
        try {
            $answer = $addOnTool->run($arguments, new ToolCall($caller->userId, $caller->keyName));
        } catch (ToolRefused $e) {
            return ToolOutcome::refused($e->getMessage(), $e->details === [] ? null : $e->details);
        } catch (\Throwable $e) {
            $this->logger->error('The add-on tool {tool} failed: {message}', ['tool' => $tool->name, 'message' => $e->getMessage(), 'exception' => $e]);

            return ToolOutcome::refused("The add-on's tool failed; the shop logged why (Shop → Status).");
        }
        if (!$tool->readOnly) {
            try {
                $this->log->record($caller->userId, 'CALL', '/assistant/' . $tool->name, '/assistant/' . $tool->name, $arguments, RouteDefinition::VIA_ASSISTANT, $caller->keyName);
            } catch (\Throwable $e) {
                $this->logger->error('The change log could not record the add-on tool {tool}: {message}', ['tool' => $tool->name, 'message' => $e->getMessage()]);
            }
        }

        return ToolOutcome::done($answer);
    }

    private function definition(AssistantTool $tool): ?ToolDefinition
    {
        $name = $tool->name();
        $problem = match (true) {
            preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1 => 'its name is not lowercase letters, digits and underscores, up to 64',
            !self::prefixed($name, $tool->addOn()) => 'its name does not start with its add-on\'s prefix (' . implode(' or ', self::prefixes($tool->addOn())) . ')',
            isset($this->taken()[$name]) => 'the shop has a tool of that name',
            ($tool->inputSchema()['type'] ?? null) !== 'object' => 'its input schema is not an object schema',
            $tool->risk() !== null && !\in_array($tool->risk(), Tool::RISKS, true) => 'its risk is not one of ' . implode(', ', Tool::RISKS),
            trim($tool->description()) === '' => 'it has no description',
            default => null,
        };
        if ($problem !== null) {
            $this->logger->error('The add-on tool "{tool}" ({class}) is left out: {problem}.', ['tool' => $name, 'class' => $tool::class, 'problem' => $problem]);

            return null;
        }

        return new ToolDefinition(
            $name,
            $tool->title(),
            $tool->description(),
            $tool->inputSchema(),
            $tool->readOnly(),
            !$tool->readOnly() && $tool->risk() !== null,
            false,
            $tool->risk(),
            trim($tool->addOn()) === '' ? 'Add-ons' : trim($tool->addOn()),
            $tool->capability(),
            true,
        );
    }

    /**
     * The names the shop's own tools take.
     *
     * @return array<string, true>
     */
    private function taken(): array
    {
        if ($this->taken === null) {
            $this->taken = array_fill_keys(McpServer::FINDER, true);
            foreach ($this->others as $source) {
                if ($source !== $this) {
                    foreach ($source->tools() as $tool) {
                        $this->taken[$tool->name] = true;
                    }
                }
            }
        }

        return $this->taken;
    }

    public static function prefixed(string $name, string $addOn): bool
    {
        foreach (self::prefixes($addOn) as $prefix) {
            if (\strlen($name) > \strlen($prefix) && str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Parcel Co": parcelco_ and parcel_co_. None for an add-on without a name in letters or digits.
     *
     * @return list<string>
     */
    public static function prefixes(string $addOn): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($addOn), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return [];
        }

        return array_values(array_unique([implode('', $words) . '_', implode('_', $words) . '_']));
    }
}
