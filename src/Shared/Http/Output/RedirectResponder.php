<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Output;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends the browser on to another address, for routes customers reach by following a link, such as the
 * return from a payment provider.
 */
final readonly class RedirectResponder
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function to(string $url): ResponseInterface
    {
        return $this->responses->createResponse(303)->withHeader('Location', $url);
    }
}
