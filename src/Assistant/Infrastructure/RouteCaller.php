<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure;

use OrillaCart\Assistant\Application\Dto\RouteTool;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Assistant\Infrastructure\WordPress\ToolFiles;
use OrillaCart\Shared\Http\HttpKernel;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;

/**
 * Runs a tool by its route: the request the admin's screen would send is built here and handed to the HTTP
 * kernel, so it passes the capability check, the key's scope, validation and the change log as that request
 * would. The route's answer is the tool's; a refusal is the tool's error, with each field's violation for the
 * model to correct.
 */
final readonly class RouteCaller
{
    public function __construct(
        private RouteCollection $routes,
        private HttpKernel $kernel,
        private ServerRequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private UploadedFileFactoryInterface $uploads,
        private ToolFiles $files,
    ) {}

    /**
     * Calls the tool's route with its arguments: the placeholders' values from them (or from $fixed, which the
     * tool's source sets itself), the rest as the body or the query, a file as the upload.
     *
     * @param array<string, mixed>  $arguments
     * @param array<string, string> $fixed     placeholder values the source gives, not the model
     */
    public function call(RouteTool $routeTool, array $arguments, array $fixed = []): ToolOutcome
    {
        $route = $this->route($routeTool);
        if ($route === null) {
            return ToolOutcome::refused("The shop no longer has the tool {$routeTool->name}.");
        }

        $path = $routeTool->path;
        $values = [];
        foreach ($fixed as $placeholder => $value) {
            $values[$placeholder] = $value;
            $path = str_replace('{' . $placeholder . '}', rawurlencode($value), $path);
        }
        foreach ($routeTool->placeholders as $placeholder => $argument) {
            $value = $arguments[$argument] ?? null;
            unset($arguments[$argument]);
            if (!\is_string($value) && !\is_int($value)) {
                return ToolOutcome::refused("Give {$argument}.");
            }
            $value = (string) $value;
            if (preg_match('#^(?:' . ($route->requirements[$placeholder] ?? '[^/]+') . ')$#D', $value) !== 1) {
                return ToolOutcome::refused("{$argument} is not valid: \"{$value}\".");
            }
            $values[$placeholder] = $value;
            $path = str_replace('{' . $placeholder . '}', rawurlencode($value), $path);
        }
        $file = null;
        if ($routeTool->file) {
            $name = $arguments['file_name'] ?? null;
            if (!\is_string($name) || trim($name) === '') {
                return ToolOutcome::refused('Give file_name, with its extension.');
            }
            try {
                $file = $this->files->write($arguments);
            } catch (\InvalidArgumentException $e) {
                return ToolOutcome::refused($e->getMessage());
            }
            unset($arguments['file_name'], $arguments['file_base64'], $arguments['file_url']);
        }
        try {
            return $this->send($routeTool, $route, $path, $values, $arguments, $file === null ? null : [$file, $name]);
        } finally {
            if ($file !== null && is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param array<string, string>  $values    the placeholders' values
     * @param array<string, mixed>   $arguments the rest, for the body or the query
     * @param array{string, string}|null $file  the temporary file and its name, sent as the upload "file"
     */
    private function send(RouteTool $routeTool, RouteDefinition $route, string $path, array $values, array $arguments, ?array $file): ToolOutcome
    {
        if ($routeTool->input === null && $arguments !== []) {
            return ToolOutcome::refused('This tool takes no other arguments: ' . implode(', ', array_keys($arguments)) . '.');
        }

        $uri = rest_url(RouteCollection::REST_NAMESPACE . $path);
        $request = $this->requests->createServerRequest($routeTool->method, $uri, $_SERVER)
            ->withAttribute(RouteDefinition::ATTRIBUTE, $route)
            ->withAttribute(RouteDefinition::VIA, 'assistant');
        foreach ($values as $placeholder => $value) {
            $request = $request->withAttribute($placeholder, $value);
        }
        if ($routeTool->input === 'query') {
            $request = $request->withQueryParams($arguments);
        } elseif ($routeTool->input === 'body') {
            if ($routeTool->wholeBody !== null) {
                $whole = $arguments[$routeTool->wholeBody] ?? null;
                if (!\is_array($whole)) {
                    return ToolOutcome::refused("Give {$routeTool->wholeBody} as an object.");
                }
                $arguments = $whole;
            }
            $body = json_encode($arguments === [] ? new \stdClass() : $arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($body === false ? '{}' : $body))
                ->withParsedBody($arguments);
        }

        if ($file !== null) {
            [$temporary, $name] = $file;
            $request = $request->withUploadedFiles(['file' => $this->uploads->createUploadedFile(
                $this->streams->createStreamFromFile($temporary),
                (int) filesize($temporary),
                \UPLOAD_ERR_OK,
                $name,
                \is_string($type = mime_content_type($temporary)) ? $type : 'application/octet-stream',
            )]);
        }

        $response = $this->kernel->handle($request);
        $text = (string) $response->getBody();
        $status = $response->getStatusCode();
        if ($status < 300) {
            // Objects kept as objects, so an empty one stays {} for the client.
            return ToolOutcome::done($text === '' ? null : json_decode($text, false));
        }
        $data = $text === '' ? null : json_decode($text, true);
        $message = \is_array($data) && \is_string($data['message'] ?? null) ? $data['message'] : "The shop answered {$status}.";
        $violations = \is_array($data) && \is_array($data['data'] ?? null) ? ($data['data']['violations'] ?? null) : null;

        return ToolOutcome::refused($message, \is_array($violations) && $violations !== [] ? ['violations' => $violations] : null);
    }

    private function route(RouteTool $tool): ?RouteDefinition
    {
        foreach ($this->routes->all() as $route) {
            if ($route->path === $tool->path && \in_array($tool->method, $route->methods, true)) {
                return $route;
            }
        }

        return null;
    }
}
