<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop's own source of the template an extension's file overrides, for the diff beside the editor; `original` is null for a file that overrides nothing. */
#[Route('/extensions/{id}/files/{path}/original', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*', 'path' => '.+'])]
final readonly class ShowPackageFileOriginalAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('path');

        return $this->responder->ok(['original' => $this->packages->original(ShowPackageAction::id($request), \is_string($path) ? rawurldecode($path) : '')]);
    }
}
