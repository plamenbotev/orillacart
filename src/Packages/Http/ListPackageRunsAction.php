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

/** The latest runs of an extension's handlers and console functions, newest first: what ran, how it ended, its message and the budget it used. */
#[Route('/extensions/{id}/runs', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class ListPackageRunsAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $limit = $request->getQueryParams()['limit'] ?? 50;

        return $this->responder->ok(['runs' => $this->packages->runs(ShowPackageAction::id($request), is_numeric($limit) ? (int) $limit : 50)]);
    }
}
