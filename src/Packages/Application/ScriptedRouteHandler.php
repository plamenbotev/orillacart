<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\ScriptResponse;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Packages\Domain\PackageState;
use OrillaCart\Scripting\Domain\Ops;

/**
 * Answers a scripted route: finds the extension and declaration a method and path are for, runs the handler with
 * the request as a map, and gives back what `respond` made, every failure as an error response.
 */
final readonly class ScriptedRouteHandler
{
    public function __construct(private ScriptedRoutes $routes, private ScriptRunner $runner) {}

    /**
     * @param array<string, mixed> $request as the script sees it
     */
    public function handle(string $method, string $path, array $request): ScriptResponse
    {
        $found = $this->routes->handler($method, $path);
        if ($found === null) {
            return new ScriptResponse(ScriptResponse::ERROR, 404, message: 'No extension answers here.');
        }
        [$loaded, $declaration] = $found;
        if ($loaded->package->state() === PackageState::Stopped) {
            return new ScriptResponse(ScriptResponse::ERROR, 503, message: \sprintf('The extension "%s" is stopped after failing; a manager enables it again.', $loaded->package->name()));
        }
        try {
            $answer = $this->runner->run($loaded, (string) $declaration['handler'], [$request]);
        } catch (HandlerFailed $e) {
            return match ($e->kind) {
                HandlerFailed::REFUSED => new ScriptResponse(ScriptResponse::ERROR, 400, message: $e->getMessage()),
                HandlerFailed::UNAVAILABLE => new ScriptResponse(ScriptResponse::ERROR, 503, message: $e->getMessage()),
                default => new ScriptResponse(ScriptResponse::ERROR, 500, message: 'The extension failed; the shop logged it.'),
            };
        }
        if ($answer instanceof ScriptResponse) {
            if ($answer->kind === ScriptResponse::HTML) {
                return new ScriptResponse(ScriptResponse::HTML, $answer->status, $answer->data, PackageTemplates::PREFIX . $loaded->package->slug() . '/' . ltrim((string) $answer->template, '/'));
            }

            return $answer;
        }
        // A map or a value: JSON, as the common case needs no respond.json().
        $plain = Ops::plain($answer);

        return new ScriptResponse(ScriptResponse::JSON, 200, \is_array($plain) ? self::map($plain) : ['result' => $plain]);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
