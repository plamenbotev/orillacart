<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Http;

use OrillaCart\Forms\Application\Dto\SaveForm;
use OrillaCart\Forms\Application\FormService;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Changes a form's settings and replaces all its fields; a form stays where it was made, on product pages or at
 * checkout.
 */
#[Route('/forms/{id}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['id' => '\d+'])]
final readonly class UpdateFormAction implements RequestHandlerInterface
{
    public function __construct(
        private FormService $forms,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->forms->update(PathParameter::id($request), $this->input->body($request, SaveForm::class)));
    }
}
