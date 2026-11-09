<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Scripting\Application\ScriptGuide;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The written guide to extension scripts, as Markdown, for whoever writes an extension; /script-reference is the generated list beside it. */
#[Route('/script-guide', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowScriptGuideAction implements RequestHandlerInterface
{
    public function __construct(private ScriptGuide $guide, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(['markdown' => $this->guide->markdown()]);
    }
}
