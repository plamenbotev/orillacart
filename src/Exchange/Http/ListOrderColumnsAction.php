<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Http;

use OrillaCart\Exchange\Application\OrdersExport;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The columns an orders export can hold: the chosen ones in their order, then the others; nothing changes. */
#[Route('/exports/orders/columns', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListOrderColumnsAction implements RequestHandlerInterface
{
    public function __construct(
        private OrdersExport $export,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->export->columns());
    }
}
