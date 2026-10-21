<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Http;

use OrillaCart\Reviews\Application\Dto\WriteReview;
use OrillaCart\Reviews\Application\Reviews;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Security\Authorization;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A visitor reviews a product, as the shop's policy and the site's discussion settings let them.
 */
#[Route('/products/{id}/reviews', methods: ['POST'], capability: Route::PUBLIC, requirements: ['id' => '\d+'], guard: 'reviews')]
final readonly class WriteReviewAction implements RequestHandlerInterface
{
    public function __construct(
        private Reviews $reviews,
        private Authorization $authorization,
        private RequestMapper $input,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $written = $this->reviews->write(PathParameter::id($request), $this->input->body($request, WriteReview::class), $this->authorization->userId());

        return $this->responder->ok($written, 201);
    }
}
