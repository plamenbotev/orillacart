<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Http;

use OrillaCart\Returns\Application\Dto\AnswerReturn;
use OrillaCart\Returns\Application\Returns;
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

/**
 * Approves a return, telling the customer how to send the items.
 */
#[Tool(risk: Tool::CUSTOMERS)]
#[Route('/returns/{id}/approve', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class ApproveReturnAction implements RequestHandlerInterface
{
    public function __construct(
        private Returns $returns,
        private RequestMapper $input,
        private Authorization $authorization,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->returns->approve(PathParameter::id($request), $this->input->body($request, AnswerReturn::class), $this->authorization->userId()));
    }
}
