<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application\Dto;

/**
 * One of the assistant's tools as the client sees it.
 */
final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed> $inputSchema JSON Schema 2020-12
     * @param string               $area        what the owner chooses by: a business area ("Ordering"), or an add-on's name
     * @param string|null          $risk        one of Tool::RISKS
     * @param string               $capability  the WordPress capability the user needs
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array $inputSchema,
        public bool $readOnly,
        public bool $destructive,
        public bool $idempotent,
        public ?string $risk,
        public string $area,
        public string $capability,
    ) {}

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
