<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Http;

use OrillaCart\Reports\Application\Home;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The Home screen in one answer: today's and the week's figures, what needs the shop manager, the latest orders,
 * and what add-ons put there; nothing changes.
 */
#[Route('/home', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowHomeAction implements RequestHandlerInterface
{
    public function __construct(
        private Home $home,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->home->overview());
    }
}
