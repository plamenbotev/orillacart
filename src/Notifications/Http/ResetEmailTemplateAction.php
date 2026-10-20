<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Http;

use OrillaCart\Notifications\Application\EmailTemplateEditor;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Goes back to the template's file; answers with it. */
#[Route('/email-templates/{name}', methods: ['DELETE'], capability: Capability::MANAGE_SHOP, requirements: ['name' => '[a-z_-]+'])]
final readonly class ResetEmailTemplateAction implements RequestHandlerInterface
{
    public function __construct(
        private EmailTemplateEditor $editor,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->editor->reset(PathParameter::string($request, 'name')));
    }
}
