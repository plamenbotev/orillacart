<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Assistant\Application\RouteTools;
use OrillaCart\Assistant\Application\ToolSource;
use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Extension\Assistant\AssistantTool;
use OrillaCart\Extension\Assistant\ToolCall;
use OrillaCart\Extension\Assistant\ToolRefused;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Routing\Tool;
use Psr\Log\LoggerInterface;

/**
 * Add-ons' tools (extension API 15), under each add-on's name. One whose name or schema is not well formed, or
 * takes a name the shop's tools have, is left out and logged. A call that changes something is written into the
 * change log as the assistant's, as a route's would be. The tools are checked once per request.
 */
final class AddOnToolSource implements ToolSource
{
    /** @var list<ToolDefinition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly InstalledExtensions $extensions,
        private readonly RouteTools $routeTools,
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
            $this->routeTools->get($name) !== null => 'the shop has a tool of that name',
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
}
