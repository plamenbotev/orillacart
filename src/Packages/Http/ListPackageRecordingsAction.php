<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\PackageRecordings;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The recordings an extension's runs left for its debugger, newest first (kept a day), and the live capture armed for it, if any. */
#[Route('/extensions/{id}/recordings', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class ListPackageRecordingsAction implements RequestHandlerInterface
{
    public function __construct(private PackageRecordings $recordings, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->recordings->list(ShowPackageAction::id($request)));
    }
}
