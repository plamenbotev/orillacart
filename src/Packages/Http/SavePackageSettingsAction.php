<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\SavePackageSettings;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Saves the values of the settings an extension declares; a secret sent empty keeps its value, false clears it. */
#[Tool(unasked: 'the values of an extension\'s own settings')]
#[Route('/extensions/{id}/settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class SavePackageSettingsAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->packages->saveSettings(ShowPackageAction::id($request), $this->input->body($request, SavePackageSettings::class)));
    }
}
