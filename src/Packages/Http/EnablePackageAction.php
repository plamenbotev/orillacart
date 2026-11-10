<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\EnablePackage;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Switches an extension on with the permissions granted, which must cover what it declares it needs; it must
 * compile. From then on its providers, routes and handlers run in the shop with those permissions, from the files
 * as they are now until it is enabled again. One with templates or browser files (assets/) is enabled only by a
 * user who may publish raw HTML (`unfiltered_html`: an administrator, a super admin on a network).
 */
#[Tool(risk: Tool::OPENING)]
#[Route('/extensions/{id}/enable', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '[a-z][a-z0-9_]*'])]
final readonly class EnablePackageAction implements RequestHandlerInterface
{
    public function __construct(private PackageService $packages, private RequestMapper $input, private JsonResponder $responder, private Authorization $authorization) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->packages->enable(ShowPackageAction::id($request), $this->input->body($request, EnablePackage::class), $this->authorization->isGranted('unfiltered_html')));
    }
}
