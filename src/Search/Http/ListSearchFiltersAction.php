<?php

declare(strict_types=1);

namespace OrillaCart\Search\Http;

use OrillaCart\Search\Application\SearchFilters;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Lists the filters shoppers see in the shop, in their order; nothing changes. */
#[Route('/search-filters', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListSearchFiltersAction implements RequestHandlerInterface
{
    public function __construct(
        private SearchFilters $filters,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->filters->filters());
    }
}
