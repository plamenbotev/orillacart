<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\Dto\ChangeDownloadAllowance;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/orders/{id}/downloads/{grant}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+', 'grant' => '\d+'])]
final readonly class ChangeDownloadAllowanceAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Authorization $authorization,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->orders->changeDownloadAllowance(
            PathParameter::id($request),
            PathParameter::id($request, 'grant'),
            $this->input->body($request, ChangeDownloadAllowance::class),
            $this->authorization->userId(),
        ));
    }
}
