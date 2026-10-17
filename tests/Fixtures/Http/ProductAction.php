<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Http;

use Nyholm\Psr7\Response;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/products/{id}', methods: ['GET'], capability: Route::PUBLIC, requirements: ['id' => '\d+'])]
#[Route('/products/{id}', methods: ['DELETE'], capability: 'manage_orillacart', requirements: ['id' => '\d+'])]
final class ProductAction implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response();
    }
}
