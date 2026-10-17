<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Http;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Public: checkout and account forms list the countries before anyone logs in.
 */
#[Route('/countries', methods: ['GET'], capability: Route::PUBLIC)]
final readonly class ListCountriesAction implements RequestHandlerInterface
{
    public function __construct(
        private GeographyService $geography,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->geography->countries());
    }
}
