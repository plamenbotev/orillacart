<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Http;

use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The MCP endpoint keeps no stream open: a client that asks for one is told to POST (405).
 */
#[Tool(leftOut: 'The assistant\'s own endpoint.')]
#[Route('/mcp', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class McpStreamAction implements RequestHandlerInterface
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->createResponse(405)->withHeader('Allow', 'POST');
    }
}
