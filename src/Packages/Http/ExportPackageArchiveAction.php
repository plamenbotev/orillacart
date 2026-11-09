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

/**
 * An extension as a zip to keep, edit or install on another shop: its files, and an extension.json with its id,
 * name, version and translations. The zip is base64, with its file name. Settings and permissions stay with the shop.
 */
#[Route('/extensions/{id}/archive', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class ExportPackageArchiveAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->packages->archive(ShowPackageAction::id($request)));
    }
}
