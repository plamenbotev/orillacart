<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Http;

use OrillaCart\Exchange\Application\Dto\ChooseColumns;
use OrillaCart\Exchange\Application\OrdersExport;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Chooses which columns the orders export holds, in this order; answers with every column, the chosen ones first. */
#[Route('/exports/orders/columns', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class ChooseOrderColumnsAction implements RequestHandlerInterface
{
    public function __construct(
        private OrdersExport $export,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->export->choose($this->input->body($request, ChooseColumns::class)));
    }
}
