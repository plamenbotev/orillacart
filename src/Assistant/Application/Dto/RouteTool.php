<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application\Dto;

/**
 * A shop manager's route as one of the assistant's tools.
 */
final readonly class RouteTool
{
    /**
     * @param string                $area         the business area the route belongs to: "Ordering"
     * @param array<string, string> $placeholders the argument each of the path's placeholders is given as
     * @param 'body'|'query'|null   $input        where the arguments that are not placeholders go
     * @param array<string, mixed>  $inputSchema  JSON Schema 2020-12
     * @param string|null           $risk         one of Tool::RISKS
     * @param bool                  $file         the route takes an uploaded file in "file"
     * @param string|null           $wholeBody    the argument that is the whole body, when the action reads it without a DTO
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public string $area,
        public string $method,
        public string $path,
        public array $placeholders,
        public ?string $input,
        public array $inputSchema,
        public bool $readOnly,
        public bool $destructive,
        public bool $idempotent,
        public ?string $risk,
        public string $capability,
        public bool $file = false,
        public ?string $wholeBody = null,
    ) {}

    /**
     * @param array{name: string, title: string, description: string, area: string, method: string, path: string, placeholders: array<string, string>, input: 'body'|'query'|null, inputSchema: array<string, mixed>, readOnly: bool, destructive: bool, idempotent: bool, risk: ?string, capability: string, file?: bool, wholeBody?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }

    /**
     * @return array{name: string, title: string, description: string, area: string, method: string, path: string, placeholders: array<string, string>, input: 'body'|'query'|null, inputSchema: array<string, mixed>, readOnly: bool, destructive: bool, idempotent: bool, risk: ?string, capability: string, file: bool, wholeBody: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'area' => $this->area,
            'method' => $this->method,
            'path' => $this->path,
            'placeholders' => $this->placeholders,
            'input' => $this->input,
            'inputSchema' => $this->inputSchema,
            'readOnly' => $this->readOnly,
            'destructive' => $this->destructive,
            'idempotent' => $this->idempotent,
            'risk' => $this->risk,
            'capability' => $this->capability,
            'file' => $this->file,
            'wholeBody' => $this->wholeBody,
        ];
    }
}
