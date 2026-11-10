<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stands where a route would for a request the adapter refused before routing (HttpKernel::refuse()): it throws
 * the reason, for the error middleware to answer.
 */
final readonly class RefusedRequest implements RequestHandlerInterface
{
    public function __construct(private \Throwable $reason) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw $this->reason;
    }
}
