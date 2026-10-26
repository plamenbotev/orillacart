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

/** Lists the attribute names products use, to build search filters from; nothing changes. */
#[Route('/search-filters/attributes', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListAttributeNamesAction implements RequestHandlerInterface
{
    public function __construct(
        private SearchFilters $filters,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->filters->attributeNames());
    }
}
