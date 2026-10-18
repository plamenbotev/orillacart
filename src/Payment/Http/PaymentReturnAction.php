<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Http;

use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\QueryStrings;
use OrillaCart\Shared\Http\Output\RedirectResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Providers send customers back here after they paid; the customer then sees the order's receipt.
 */
#[Route('/payments/{gateway}/return', methods: ['GET'], capability: Route::PUBLIC, requirements: ['gateway' => '[a-z][a-z0-9_]{0,39}'])]
final readonly class PaymentReturnAction implements RequestHandlerInterface
{
    public function __construct(
        private PaymentOutcomes $outcomes,
        private RedirectResponder $redirect,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->redirect->to($this->outcomes->returned(PathParameter::string($request, 'gateway'), QueryStrings::of($request), cancelled: false));
    }
}
