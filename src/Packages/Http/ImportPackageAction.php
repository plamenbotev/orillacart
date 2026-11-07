<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\PackageExport;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Puts an exported extension in place: a new one as a draft, or the files of the one with the same id replaced (it keeps running its old files until enabled again). */
#[Route('/extensions/import', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class ImportPackageAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->packages->import($this->input->body($request, PackageExport::class)), 201);
    }
}
