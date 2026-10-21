<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Http;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Audit\Application\Dto\ChangeLogQuery;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The change log, newest first: ?area=payment-methods&user_id=1&page=2. */
#[Route('/change-log', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowChangeLogAction implements RequestHandlerInterface
{
    public function __construct(
        private ChangeLog $log,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->log->page($this->input->query($request, ChangeLogQuery::class)));
    }
}
