<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Removes one file of an extension; the files before are kept as a version, and an enabled extension runs its old files until enabled again. */
#[Tool(unasked: 'one file of an extension removed, kept in its versions to restore; nothing runs until enabled, which asks')]
#[Route('/extensions/{id}/files/{path}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*', 'path' => '.+'])]
final readonly class DeletePackageFileAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('path');

        return $this->responder->ok($this->packages->deleteFile(ShowPackageAction::id($request), \is_string($path) ? rawurldecode($path) : ''));
    }
}
