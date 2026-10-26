<?php

declare(strict_types=1);

namespace OrillaCart\Search\Http;

use OrillaCart\Search\Application\SearchFilters;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Removes a filter from the shop's search. */
#[Route('/search-filters/{id}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class DeleteSearchFilterAction implements RequestHandlerInterface
{
    public function __construct(
        private SearchFilters $filters,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->filters->delete(PathParameter::id($request));

        return $this->responder->noContent();
    }
}
