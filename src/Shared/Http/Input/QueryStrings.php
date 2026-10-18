<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Input;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The query string's plain values by name, for callers that pass on what an outside party sent, as payment
 * providers do. Arrays (name[]=…) are left out.
 */
final class QueryStrings
{
    /**
     * @return array<string, string>
     */
    public static function of(ServerRequestInterface $request): array
    {
        $strings = [];
        foreach ($request->getQueryParams() as $name => $value) {
            if (\is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }
}
