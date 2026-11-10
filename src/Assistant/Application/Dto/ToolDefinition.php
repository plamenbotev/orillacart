<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application\Dto;

use OrillaCart\Shared\Http\Routing\Tool;

/**
 * One of the assistant's tools as the client sees it.
 *
 * A tool that changes something waits for the owner's yes whatever its source: when it names no risk and does
 * not say why it need not ask, its risk is Tool::CHANGES. Only a tool that only reads, or one that says why,
 * runs unasked.
 */
final readonly class ToolDefinition
{
    /** one of Tool::RISKS, or null when the tool only reads or says why it need not ask */
    public ?string $risk;

    /** whether the tool changes what it cannot take back; every tool that asks is */
    public bool $destructive;

    /**
     * @param array<string, mixed> $inputSchema JSON Schema 2020-12
     * @param string|null          $risk        one of Tool::RISKS; Tool::CHANGES when the tool writes and says nothing
     * @param string               $area        what the owner chooses by: a business area ("Ordering"), or an add-on's name
     * @param string               $capability  the WordPress capability the user needs
     * @param bool                 $addOn       an add-on's tool: the owner ticks it per key
     * @param string|null          $unasked     why the tool changes something without the owner's yes
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array $inputSchema,
        public bool $readOnly,
        bool $destructive,
        public bool $idempotent,
        ?string $risk,
        public string $area,
        public string $capability,
        public bool $addOn = false,
        public ?string $unasked = null,
    ) {
        $this->risk = $risk ?? ($readOnly || ($unasked !== null && trim($unasked) !== '') ? null : Tool::CHANGES);
        $this->destructive = !$readOnly && ($destructive || $this->risk !== null);
    }

    /**
     * The tool as MCP lists it.
     *
     * @return array<string, mixed>
     */
    public function toMcp(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'annotations' => [
                'title' => $this->title,
                'readOnlyHint' => $this->readOnly,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => $this->idempotent,
                'openWorldHint' => false,
            ],
        ];
    }
}
