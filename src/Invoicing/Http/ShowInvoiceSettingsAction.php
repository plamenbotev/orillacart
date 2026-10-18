<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Http;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route('/invoice-settings', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowInvoiceSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private Invoices $invoices,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->invoices->settings());
    }
}
