<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Http;

use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\Dto\CurrencySettingsView;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Replaces the currencies shoppers may pay in besides the shop's own, with their rates and rounding. Shoppers pay the
 * converted prices from then on.
 */
#[Route('/currencies', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateCurrenciesAction implements RequestHandlerInterface
{
    public function __construct(
        private Currencies $currencies,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->currencies->save($this->input->body($request, CurrencySettingsView::class)));
    }
}
