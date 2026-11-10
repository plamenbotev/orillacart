<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Customers\Application\TestData;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Removes every test order (an open one cancelled first, so its stock comes back) and every test customer's account; answers how many went. Sales and real customers are never touched. */
#[Route('/test-data', methods: ['DELETE'], capability: Capability::MANAGE_SHOP)]
final readonly class RemoveTestDataAction implements RequestHandlerInterface
{
    public function __construct(private TestData $data, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->data->remove());
    }
}
