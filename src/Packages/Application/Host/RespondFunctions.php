<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Host;

use OrillaCart\Packages\Application\Dto\ScriptResponse;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;

/** `respond`: what a route answers with. A page's handler returns a map instead, the data for its template. */
final readonly class RespondFunctions implements HostNamespace
{
    public function functions(): array
    {
        return [
            new HostFunction('respond.json', static fn(Context $c, mixed $data, mixed $status = 200): ScriptResponse => new ScriptResponse(ScriptResponse::JSON, self::status($status, 200, 299), self::map(Ops::plain($data))), 1, 2, 'respond.json(data, status?): the data as JSON'),
            new HostFunction('respond.html', static fn(Context $c, mixed $template, mixed $data = [], mixed $status = 200): ScriptResponse => new ScriptResponse(ScriptResponse::HTML, self::status($status, 200, 299), self::map($data), Values::text($template)), 1, 3, 'respond.html(template, data?, status?): one of the extension\'s templates rendered with the data; a route answers it as JSON { html }'),
            new HostFunction('respond.redirect', static fn(Context $c, mixed $url, mixed $status = 302): ScriptResponse => new ScriptResponse(ScriptResponse::REDIRECT, self::status($status, 301, 308), null, null, Values::text($url)), 1, 2, 'respond.redirect(url, status?): send the caller elsewhere'),
            new HostFunction('respond.error', static fn(Context $c, mixed $status, mixed $message): ScriptResponse => new ScriptResponse(ScriptResponse::ERROR, self::status($status, 400, 599), null, null, null, Values::text($message)), 2, 2, 'respond.error(status, message): refuse the request with a status of 400 to 599 and a message'),
        ];
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return ['result' => $value];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    private static function status(mixed $status, int $least, int $most): int
    {
        $code = (int) Values::text($status);
        if ($code < $least || $code > $most) {
            throw new ScriptError(\sprintf('A status here is %d to %d, not %d', $least, $most, $code));
        }

        return $code;
    }
}
