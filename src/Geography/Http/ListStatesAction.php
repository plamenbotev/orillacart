<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Http;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

// WordPress matches routes case-insensitively, so "us" reaches the action too.
#[Route('/countries/{code}/states', methods: ['GET'], capability: Route::PUBLIC, requirements: ['code' => '[A-Z]{2}'])]
final readonly class ListStatesAction implements RequestHandlerInterface
{
    public function __construct(
        private GeographyService $geography,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->geography->states(strtoupper(PathParameter::string($request, 'code'))));
    }
}
