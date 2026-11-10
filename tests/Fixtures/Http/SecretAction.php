<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Http;

use Nyholm\Psr7\Response;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A shop manager's route kept from the assistant: keys may not call it either. */
#[Tool(leftOut: 'Its answer holds secrets.')]
#[Route('/secrets', methods: ['GET'], capability: 'manage_orillacart')]
final class SecretAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response();
    }
}
