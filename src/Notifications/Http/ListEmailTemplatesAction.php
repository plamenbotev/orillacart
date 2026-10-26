<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Http;

use OrillaCart\Notifications\Application\EmailTemplateEditor;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The shop's email templates, which the shop has edited and when, and which a preview can show; nothing changes. */
#[Route('/email-templates', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ListEmailTemplatesAction implements RequestHandlerInterface
{
    public function __construct(
        private EmailTemplateEditor $editor,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->editor->templates());
    }
}
