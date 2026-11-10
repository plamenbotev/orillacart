<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Http;

use Nyholm\Psr7\Response;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A shop manager's route kept from the assistant that says keys may call it, as the assistant's endpoint does. */
#[Tool(leftOut: 'The assistant\'s own.')]
#[Route('/endpoint', methods: ['POST'], capability: 'manage_orillacart', keys: true)]
final class OpenedSecretAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response();
    }
}
