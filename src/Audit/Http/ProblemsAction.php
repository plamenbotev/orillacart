<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Http;

use OrillaCart\Audit\Application\ShopProblems;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The problems the shop logged, the latest first (?page=2), and clearing them (DELETE). */
#[Tool(method: 'GET', name: 'list_problems', description: 'The problems the shop logged, the latest first, a page at a time; nothing changes.')]
#[Tool(method: 'DELETE', name: 'clear_problems', description: 'Clears the problems the shop logged.')]
#[Route('/problems', methods: ['GET', 'DELETE'], capability: Capability::MANAGE_SHOP)]
final readonly class ProblemsAction implements RequestHandlerInterface
{
    public function __construct(
        private ShopProblems $problems,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'DELETE') {
            $this->problems->clear();

            return $this->responder->noContent();
        }
        $page = $request->getQueryParams()['page'] ?? '1';

        return $this->responder->ok($this->problems->page(\is_string($page) && ctype_digit($page) ? min((int) $page, 10_000) : 1));
    }
}
