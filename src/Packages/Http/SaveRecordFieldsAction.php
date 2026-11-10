<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\Dto\SaveRecordFields;
use OrillaCart\Packages\Application\ExtensionFields;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Saves an extension's fields on a product, an order or a customer: the fields sent change, the others stay,
 * null clears one. Each value is checked as the extension's own `fields.set` would be; a field the extension
 * declared read-only is refused. Answers the extension's fields on the record afterwards.
 */
#[Tool(unasked: 'an extension\'s fields on a product, an order or a customer, as the record\'s screen saves them; nobody is told')]
#[Route('/{kind}/{id}/extensions/{extension}', methods: ['PUT'], capability: Capability::MANAGE_SHOP, requirements: ['kind' => 'products|orders|customers', 'id' => '\d+', 'extension' => '[a-z][a-z0-9_]*'])]
final readonly class SaveRecordFieldsAction implements RequestHandlerInterface
{
    public function __construct(private ExtensionFields $fields, private RequestMapper $input, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $command = $this->input->body($request, SaveRecordFields::class);
        $owner = ExtensionFields::KINDS[PathParameter::string($request, 'kind')] ?? 'product';

        return $this->responder->ok(['fields' => $this->fields->save($owner, PathParameter::id($request), PathParameter::string($request, 'extension'), $command->fields)]);
    }
}
