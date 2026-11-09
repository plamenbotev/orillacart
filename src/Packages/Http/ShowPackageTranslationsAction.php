<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\PackageTranslations;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The words an extension shows people and their translations: each word with its key, its plural and context,
 * where it stands in the extension, and its forms in each of the site's other languages; the languages with how
 * many plural forms each has. Words come from the declarations (labels, names, help) and the scripts' and
 * templates' __(), _x(), _n() and _nx() calls. On a site in one language there are no languages to translate into.
 */
#[Route('/extensions/{id}/translations', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class ShowPackageTranslationsAction implements RequestHandlerInterface
{
    public function __construct(private PackageTranslations $translations, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->translations->show(ShowPackageAction::id($request)));
    }
}
