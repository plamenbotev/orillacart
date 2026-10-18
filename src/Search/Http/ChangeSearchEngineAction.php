<?php

declare(strict_types=1);

namespace OrillaCart\Search\Http;

use OrillaCart\Search\Application\Dto\ChangeSearchEngine;
use OrillaCart\Search\Application\SearchEngines;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Switches the search engine after checking OpenSearch answers; the view says what the check found.
 */
#[Route('/search-engine', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class ChangeSearchEngineAction implements RequestHandlerInterface
{
    public function __construct(
        private SearchEngines $engines,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->engines->change($this->input->body($request, ChangeSearchEngine::class)));
    }
}
