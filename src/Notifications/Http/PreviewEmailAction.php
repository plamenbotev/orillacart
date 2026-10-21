<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Http;

use OrillaCart\Notifications\Application\Dto\PreviewEmail;
use OrillaCart\Notifications\Application\EmailNotWritten;
use OrillaCart\Notifications\Application\EmailTemplateEditor;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The email a template makes, saved or as edited; a template that fails as it renders says why (422). */
#[Route('/email-templates/{name}/preview', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['name' => '[a-z_-]+'], changes: false)]
final readonly class PreviewEmailAction implements RequestHandlerInterface
{
    public function __construct(
        private EmailTemplateEditor $editor,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->responder->ok($this->editor->preview(PathParameter::string($request, 'name'), $this->input->body($request, PreviewEmail::class)));
        } catch (EmailNotWritten $e) {
            return $this->responder->error(422, 'orillacart_template_failed', $e->getPrevious()?->getMessage() ?? $e->getMessage());
        }
    }
}
