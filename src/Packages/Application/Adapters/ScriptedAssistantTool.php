<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Assistant\AssistantTool;
use OrillaCart\Extension\Assistant\ToolCall;
use OrillaCart\Extension\Assistant\ToolRefused;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;

/**
 * `assistant_tool "name" { title, description, input, read_only, risk; fn run(arguments, call) }`: a tool of the
 * assistant, run with what the agent passes; `fail refused(…)` is the refusal the agent reads.
 */
final class ScriptedAssistantTool extends ScriptedProvider implements AssistantTool
{
    public function name(): string
    {
        return $this->id();
    }

    public function addOn(): string
    {
        return $this->loaded->package->slug();
    }

    public function title(): string
    {
        $title = $this->declaration['props']['title'] ?? null;

        return \is_string($title) ? $title : parent::title();
    }

    public function description(): string
    {
        $description = $this->declaration['props']['description'] ?? null;

        return \is_string($description) ? $description : '';
    }

    /**
     * `input` is a JSON schema (`{ type: "object", properties: { … }, required: [ … ] }`), or the short form of the
     * object fields (`{ company_id: number, items: text { required: true } }`), which becomes one.
     */
    public function inputSchema(): array
    {
        $input = $this->declaration['props']['input'] ?? null;
        $schema = [];
        foreach (\is_array($input) ? $input : [] as $key => $value) {
            $schema[(string) $key] = $value;
        }
        if ($schema === []) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        if (isset($schema['type']) || isset($schema['properties'])) {
            return $schema;
        }
        $properties = [];
        $required = [];
        foreach ($schema as $name => $spec) {
            $kind = \is_array($spec) ? ($spec['$spec'] ?? 'text') : $spec;
            $options = \is_array($spec) ? $spec : [];
            $property = match (\is_string($kind) ? $kind : 'text') {
                'number', 'money' => ['type' => 'number'],
                'boolean' => ['type' => 'boolean'],
                'list' => ['type' => 'array'],
                'map' => ['type' => 'object'],
                'choice' => ['type' => 'string', 'enum' => array_values(array_map(static fn(mixed $choice): string => \is_array($choice) ? (\is_scalar($choice['$spec'] ?? null) ? (string) $choice['$spec'] : '') : (\is_scalar($choice) ? (string) $choice : ''), \is_array($options['of'] ?? null) ? $options['of'] : []))],
                default => ['type' => 'string'],
            };
            if (\is_string($options['help'] ?? null)) {
                $property['description'] = $options['help'];
            } elseif (\is_string($options['label'] ?? null)) {
                $property['description'] = $options['label'];
            }
            $properties[$name] = $property;
            if (($options['required'] ?? false) === true) {
                $required[] = $name;
            }
        }

        return ['type' => 'object', 'properties' => $properties] + ($required === [] ? [] : ['required' => $required]);
    }

    public function capability(): string
    {
        return 'manage_orillacart';
    }

    public function readOnly(): bool
    {
        return ($this->declaration['props']['read_only'] ?? false) === true;
    }

    public function risk(): ?string
    {
        $risk = $this->declaration['props']['risk'] ?? null;
        if (\is_array($risk) && \is_string($risk['$spec'] ?? null)) {
            $risk = $risk['$spec'];
        }

        return \is_string($risk) && \in_array($risk, [AssistantTool::MONEY, AssistantTool::DELETING, AssistantTool::CUSTOMERS, AssistantTool::OPENING, AssistantTool::PUBLISHING, AssistantTool::CHANGES], true) ? $risk : null;
    }

    public function run(array $arguments, ToolCall $call): ?array
    {
        try {
            $answer = $this->call('run', [$arguments, ['user_id' => $call->userId, 'key_name' => $call->keyName]]);
        } catch (HandlerFailed $e) {
            throw new ToolRefused($e->getMessage(), \is_array($e->data) ? $e->data : []);
        }
        if ($answer === null) {
            return null;
        }
        $plain = Ops::plain($answer);

        return \is_array($plain) ? $plain : ['result' => $plain];
    }
}
