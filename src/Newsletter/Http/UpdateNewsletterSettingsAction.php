<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Http;

use OrillaCart\Newsletter\Application\Dto\UpdateNewsletterSettings;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Changes whether the shop offers its newsletter, the words people agree to, and the mailing lists sign-ups go to; a
 * list newly turned on gets everyone already signed up.
 */
#[Route('/newsletter-settings', methods: ['PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class UpdateNewsletterSettingsAction implements RequestHandlerInterface
{
    public function __construct(
        private Newsletter $newsletter,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->newsletter->updateSettings($this->input->body($request, UpdateNewsletterSettings::class)));
    }
}
