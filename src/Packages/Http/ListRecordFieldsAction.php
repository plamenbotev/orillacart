<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Http;

use OrillaCart\Packages\Application\ExtensionFields;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The fields the enabled extensions declare on products, orders or customers: one section per extension, with
 * each field's kind, label, help, choices and whether it is required or read-only. The admin builds the
 * "Extensions" section of the record's screen from it; a record's values are in its own view under
 * extensions.<id>. Nothing changes.
 */
#[Route('/record-fields/{kind}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['kind' => 'products|orders|customers'])]
final readonly class ListRecordFieldsAction implements RequestHandlerInterface
{
    public function __construct(private ExtensionFields $fields, private JsonResponder $responder) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok(['sections' => $this->fields->declared(ExtensionFields::KINDS[PathParameter::string($request, 'kind')] ?? 'product')]);
    }
}
