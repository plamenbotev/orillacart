<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Http;

use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Shows the currencies shoppers may pay in besides the shop's own, with the shop's rates and rounding. */
#[Route('/currencies', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowCurrenciesAction implements RequestHandlerInterface
{
    public function __construct(
        private Currencies $currencies,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->currencies->settings());
    }
}
