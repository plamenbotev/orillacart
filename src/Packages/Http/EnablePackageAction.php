<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\EnablePackage;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Switches an extension on with the permissions granted, which must cover what it declares it needs; it must
 * compile. From then on its providers, routes and handlers run in the shop with those permissions.
 */
#[Tool(risk: Tool::OPENING)]
#[Route('/extensions/{id}/enable', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class EnablePackageAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->packages->enable(ShowPackageAction::id($request), $this->input->body($request, EnablePackage::class)));
    }
}
