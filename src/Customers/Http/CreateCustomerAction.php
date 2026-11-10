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
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The shop makes a customer's account itself: an email that has none, a name, a password or none (the customer sets
 * theirs through the lost-password link), and the groups it joins. Nobody is emailed: send_customer_welcome tells
 * the customer. On a network of sites the shop's staff invite the customer instead, whoever has the email: the link
 * to make the account goes to it, and the answer is 202 with the invitation, the same for every email; the
 * network's administrators, who see all its users, make the account, or add a user of another site to this one.
 */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/customers', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateCustomerAction implements RequestHandlerInterface
{
    public function __construct(
        private CustomerService $customers,
        private CustomerGroups $groups,
        private Authorization $authorization,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, CreateCustomer::class);
        $seesNetwork = $this->authorization->isGranted('manage_network_users');
        if (!$command->test && $this->customers->invites($seesNetwork)) {
            return $this->responder->ok($this->customers->invite($command, $this->authorization->userId() ?? 0), 202);
        }
        $customer = $this->customers->create($command, $seesNetwork);
        if ($command->groupIds !== []) {
            $this->groups->setGroupsOf($customer->id, new SetCustomerGroups($command->groupIds));
            $customer = $this->customers->customer($customer->id);
        }

        return $this->responder->ok($customer, 201);
    }
}
