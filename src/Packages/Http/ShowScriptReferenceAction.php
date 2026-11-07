<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The script language's reference for whoever writes an extension: every host function with what it takes and needs, the filters and the tests. */
#[Route('/script-reference', methods: ['GET'], capability: Capability::MANAGE_SHOP)]
final readonly class ShowScriptReferenceAction implements RequestHandlerInterface
{
    public function __construct(private Scripts $scripts, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok([
            'functions' => array_values(array_map(static fn(HostFunction $function): array => [
                'name' => $function->name,
                'summary' => $function->summary,
                'arguments' => [$function->leastArguments, $function->mostArguments],
                'needs' => $function->needs,
            ], $this->scripts->catalog()->all())),
            ...Scripts::vocabulary(),
        ]);
    }
}
