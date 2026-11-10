<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The customer asked to be forgotten: the order keeps its sums for the books, without their details. By hand, only
 * once the order is completed, cancelled, refunded or failed, or older than the shop keeps its history.
 */
#[Tool(risk: Tool::DELETING)]
#[Route('/orders/{id}/anonymise', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class AnonymiseOrderAction implements RequestHandlerInterface
{
    public function __construct(
        private OrderService $orders,
        private Authorization $authorization,
        private JsonResponder $responder,
        private SettingsService $settings,
        private ClockInterface $clock,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $old = $this->clock->now()->sub(new \DateInterval(\sprintf('P%dD', $this->settings->current()->historyDays)));

        return $this->responder->ok($this->orders->anonymise(PathParameter::id($request), $this->authorization->userId(), $old));
    }
}
