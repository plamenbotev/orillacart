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
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Sends the preview to whoever asks. */
#[Route('/email-templates/{name}/test', methods: ['POST'], capability: Capability::MANAGE_SHOP, requirements: ['name' => '[a-z_-]+'])]
final readonly class SendTestEmailAction implements RequestHandlerInterface
{
    public function __construct(
        private EmailTemplateEditor $editor,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Authorization $authorization,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->authorization->userId();
        if ($userId === null) {
            return $this->responder->error(401, 'orillacart_not_logged_in', 'Log in to send a test.');
        }
        try {
            return $this->responder->ok($this->editor->sendTest(PathParameter::string($request, 'name'), $this->input->body($request, PreviewEmail::class), $userId));
        } catch (EmailNotWritten $e) {
            return $this->responder->error(422, 'orillacart_template_failed', $e->getPrevious()?->getMessage() ?? $e->getMessage());
        }
    }
}
