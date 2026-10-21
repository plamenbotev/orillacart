<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Http;

use OrillaCart\Settings\Application\SetupState;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Whether the setup is finished (GET), and that it is (PUT), so the admin stops offering it. */
#[Route('/setup', methods: ['GET', 'PUT'], capability: Capability::MANAGE_SHOP)]
final readonly class SetupAction implements RequestHandlerInterface
{
    public function __construct(
        private SetupState $setup,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'PUT') {
            $this->setup->finish();
        }

        return $this->responder->ok(['finished' => $this->setup->finished()]);
    }
}
