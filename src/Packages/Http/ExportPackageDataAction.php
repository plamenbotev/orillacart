<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\ObjectStore;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The records an extension keeps (its objects), as JSON to keep before uninstalling it. */
#[Route('/extensions/{id}/data', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class ExportPackageDataAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private ObjectStore $store, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = ShowPackageAction::id($request);
        $this->packages->package($id);

        return $this->responder->ok(['extension' => $id, 'objects' => $this->store->all($id)]);
    }
}
