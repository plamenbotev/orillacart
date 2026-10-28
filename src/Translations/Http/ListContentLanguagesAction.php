<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Translations\Application\ContentTranslations;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The site's content languages, the default first; none when Polylang or WPML does not translate the site. */
#[Route('/content-languages', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListContentLanguagesAction implements RequestHandlerInterface
{
    public function __construct(
        private ContentTranslations $translations,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->translations->languages());
    }
}
