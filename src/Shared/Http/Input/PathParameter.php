<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Input;

use OrillaCart\Shared\Http\HttpException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the {placeholders} of the matched route. The route's requirements already checked their format;
 * a missing value means the action and its route disagree.
 */
final class PathParameter
{
    public static function string(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return \is_string($value) && $value !== '' ? $value : throw new \LogicException(\sprintf('The route has no "%s" placeholder.', $name));
    }

    /**
     * @return positive-int
     */
    public static function id(ServerRequestInterface $request, string $name = 'id'): int
    {
        $value = self::string($request, $name);
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return \is_int($id) && $id > 0 ? $id : throw HttpException::notFound();
    }
}
