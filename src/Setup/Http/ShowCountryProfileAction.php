<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Http;

use OrillaCart\Setup\Application\CountryProfiles;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Security\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** What the setup knows of a country: its money and units, how it taxes, what its law asks of a shop. */
#[Route('/setup/countries/{country}', methods: ['GET'], capability: Capability::MANAGE_SHOP, requirements: ['country' => '[A-Z]{2}'])]
final readonly class ShowCountryProfileAction implements RequestHandlerInterface
{
    public function __construct(
        private CountryProfiles $profiles,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->ok($this->profiles->of(PathParameter::string($request, 'country')));
    }
}
