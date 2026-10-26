<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Http;

use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Tax\Application\TaxGroupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Lists the tax groups by name, with their rates; nothing changes. */
#[Route('/tax/groups', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListTaxGroupsAction implements RequestHandlerInterface
{
    public function __construct(
        private TaxGroupService $groups,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->groups->groups());
    }
}
