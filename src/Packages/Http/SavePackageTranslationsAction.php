<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\SavePackageTranslations;
use OrillaCart\Packages\Application\PackageTranslations;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Saves translations of an extension's words, by the word's key (from its translations) and the locale: a
 * plural's forms as a list, as many as the language has, a word without one as a string. The words and languages
 * not sent keep theirs; an empty form removes it. Answers the words and translations as they are now.
 */
#[Tool(unasked: 'translations of an extension\'s words, as the shop\'s own are saved with their objects')]
#[Route('/extensions/{id}/translations', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class SavePackageTranslationsAction implements RequestHandlerInterface
{
    public function __construct(private PackageTranslations $translations, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->translations->save(ShowPackageAction::id($request), $this->input->body($request, SavePackageTranslations::class)->translations));
    }
}
