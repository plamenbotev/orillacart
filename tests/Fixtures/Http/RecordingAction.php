<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stands in for every action: answers 200, or throws what it was given, and counts its calls.
 */
final class RecordingAction implements RequestHandlerInterface
{
    public int $calls = 0;

    public function __construct(public ?\Throwable $throw = null) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
    }
}
