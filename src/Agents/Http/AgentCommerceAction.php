<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Http;

use OrillaCart\Agents\Application\AgentCommerceSwitch;
use OrillaCart\Agents\Application\AgentEndpoints;
use OrillaCart\Agents\Application\Ucp;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Shop › Integrations: whether shoppers' agents buy here (design 75), and where they find the shop. */
#[Route('/agent-commerce', methods: ['GET', 'PUT'], capability: Capability::MANAGE_SHOP)]
#[Tool(risk: Tool::OPENING, method: 'PUT', wholeBody: ['on' => 'whether shoppers\' AI agents may buy here, through the Universal Commerce Protocol'])]
final readonly class AgentCommerceAction implements RequestHandlerInterface
{
    public function __construct(
        private AgentCommerceSwitch $switch,
        private AgentEndpoints $endpoints,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'PUT') {
            $body = json_decode((string) $request->getBody(), true);
            $on = \is_array($body) ? ($body['on'] ?? null) : null;
            if (!\is_bool($on)) {
                throw new HttpException(422, 'orillacart_invalid', 'Say on: true or false.');
            }
            $this->switch->set($on);
        }

        return $this->responder->exact([
            'on' => $this->switch->on(),
            'version' => Ucp::VERSION,
            'profile' => $this->endpoints->profile(),
            'rest' => $this->endpoints->rest(),
            'mcp' => $this->endpoints->mcp(),
        ]);
    }
}
