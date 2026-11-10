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

/** How many test orders and test customers the shop has: those made to try it out, never emailed and not counted. */
#[Route('/test-data', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowTestDataAction implements RequestHandlerInterface
{
    public function __construct(private TestData $data, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->data->count());
    }
}
