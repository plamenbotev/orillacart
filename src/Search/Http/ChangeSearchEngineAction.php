<?php

declare(strict_types=1);

namespace OrillaCart\Search\Http;

use OrillaCart\Search\Application\Dto\ChangeSearchEngine;
use OrillaCart\Search\Application\SearchEngines;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Switches the search engine after checking OpenSearch answers; the view says what the check found. Pointing the
 * shop at an OpenSearch server is the site administrator's (`manage_options`): its address may be anywhere on the
 * server's network, and the shop sends it requests and reads the answers. A shop manager switches back to the
 * database search.
 */
#[Route('/search-engine', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class ChangeSearchEngineAction implements RequestHandlerInterface
{
    public function __construct(
        private SearchEngines $engines,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Authorization $authorization,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, ChangeSearchEngine::class);
        if ($command->engine === 'opensearch' && !$this->authorization->isGranted('manage_options')) {
            throw new HttpException(403, 'orillacart_search_engine_forbidden', 'Only the site\'s administrator points the shop at an OpenSearch server.');
        }

        return $this->responder->ok($this->engines->change($command));
    }
}
