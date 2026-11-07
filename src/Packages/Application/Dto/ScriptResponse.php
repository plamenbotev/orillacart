<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/** What a route's or a page's handler answers with, made by the `respond` namespace. */
final readonly class ScriptResponse
{
    public const string JSON = 'json';
    public const string HTML = 'html';
    public const string REDIRECT = 'redirect';
    public const string ERROR = 'error';

    /**
     * @param array<string, mixed>|null $data the JSON body, or a template's context
     */
    public function __construct(
        public string $kind,
        public int $status,
        public ?array $data = null,
        public ?string $template = null,
        public ?string $url = null,
        public string $message = '',
    ) {}
}
