<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\Dto\AssignOrder;
use OrillaCart\Ordering\Application\OrderCustomers;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Moves an order to a customer's account, or makes it a guest's order. */
#[Tool(risk: Tool::CUSTOMERS, area: 'Customers')]
#[Route('/orders/{id}/customer', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class AssignOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderCustomers $customers,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Authorization $authorization,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, AssignOrder::class);

        return $this->responder->ok($this->customers->assign(PathParameter::id($request), $command->customerId, $this->authorization->userId()));
    }
}
