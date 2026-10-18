<?php

declare(strict_types=1);

namespace OrillaCart\Search\Http;

use OrillaCart\Search\Application\Dto\SaveSearchFilter;
use OrillaCart\Search\Application\SearchFilters;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/search-filters', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateSearchFilterAction implements RequestHandlerInterface
{
    public function __construct(
        private SearchFilters $filters,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->filters->create($this->input->body($request, SaveSearchFilter::class)), 201);
    }
}
