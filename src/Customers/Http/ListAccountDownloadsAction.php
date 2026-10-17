<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Http;

use OrillaCart\Ordering\Application\Downloads;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every file the logged-in customer bought and may download.
 */
#[Route('/account/downloads', methods: ['GET'], capability: Capability::ACCOUNT)]
final readonly class ListAccountDownloadsAction implements RequestHandlerInterface
{
    public function __construct(
        private Downloads $downloads,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->downloads->ofCustomer($this->authorization->userId() ?? throw HttpException::unauthorized()));
    }
}
