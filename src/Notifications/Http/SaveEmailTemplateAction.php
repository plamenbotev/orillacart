<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Http;

use OrillaCart\Notifications\Application\Dto\SaveEmailTemplate;
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

#[Route('/email-templates/{name}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['name' => '[a-z_-]+'])]
final readonly class SaveEmailTemplateAction implements RequestHandlerInterface
{
    public function __construct(
        private EmailTemplateEditor $editor,
        private RequestMapper $input,
        private JsonResponder $responder,
        private Authorization $authorization,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->editor->save(PathParameter::string($request, 'name'), $this->input->body($request, SaveEmailTemplate::class), $this->authorization->userId()));
    }
}
