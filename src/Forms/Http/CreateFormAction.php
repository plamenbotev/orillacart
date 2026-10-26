<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Http;

use OrillaCart\Forms\Application\Dto\SaveForm;
use OrillaCart\Forms\Application\FormService;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Makes a new form of extra fields that customers fill in on product pages or at checkout; a field or its choices can
 * add to the price.
 */
#[Route('/forms', methods: ['POST'], capability: Capability::MANAGE_SHOP)]
final readonly class CreateFormAction implements RequestHandlerInterface
{
    public function __construct(
        private FormService $forms,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->forms->create($this->input->body($request, SaveForm::class)), 201);
    }
}
