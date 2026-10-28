<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Http;

use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Translations\Application\ContentTranslations;
use OrillaCart\Translations\Application\Dto\TranslationsQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The translations of some of a type's objects, by object id, field and locale; nothing changes. */
#[Route('/translations', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListTranslationsAction implements RequestHandlerInterface
{
    public function __construct(
        private ContentTranslations $translations,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $this->input->query($request, TranslationsQuery::class);

        // An object, not a list, even when nothing is translated yet.
        return $this->responder->ok((object) $this->translations->of($query->type, \array_slice($query->idList(), 0, TranslationsQuery::MAX_IDS)));
    }
}
