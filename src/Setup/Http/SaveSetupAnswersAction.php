<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Http;

use OrillaCart\Setup\Application\SetupService;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Middleware\CsrfMiddleware;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Keeps one step's answers, as its questions ask for them; nothing in the shop changes until the plan is applied. */
#[Route('/setup/answers/{step}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['step' => '[a-z][a-z_]{0,39}'])]
final readonly class SaveSetupAnswersAction implements RequestHandlerInterface
{
    public function __construct(
        private SetupService $setup,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if ($body === null && $request->getBody()->getSize() !== 0) {
            throw HttpException::badRequest('The request body is not valid JSON.');
        }
        $body = \is_array($body) ? $body : [];
        unset($body[CsrfMiddleware::FIELD]);

        return $this->responder->ok($this->setup->answer(PathParameter::string($request, 'step'), $body));
    }
}
