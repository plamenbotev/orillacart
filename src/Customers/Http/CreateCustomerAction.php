<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\CustomerGroups;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\CreateCustomer;
use OrillaCart\Customers\Application\Dto\SetCustomerGroups;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop makes a customer's account itself: an email that has none, a name, a password or none (the customer sets theirs through the lost-password link), the groups it joins, and whether the welcome email goes. */
#[Route('/customers', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateCustomerAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private CustomerGroups $groups,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, CreateCustomer::class);
        $customer = $this->customers->create($command);
        if ($command->groupIds !== []) {
            $this->groups->setGroupsOf($customer->id, new SetCustomerGroups($command->groupIds));
            $customer = $this->customers->customer($customer->id);
        }

        return $this->responder->ok($customer, 201);
    }
}
