<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Http;

use OrillaCart\Reviews\Application\Reviews;
use OrillaCart\Shared\Http\Input\PathParameter;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\Http\Routing\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A page of a product's published reviews, as its page shows them to anyone.
 */
#[Route('/products/{id}/reviews', methods: ['GET'], capability: Route::PUBLIC, requirements: ['id' => '\d+'])]
final readonly class ListReviewsAction implements RequestHandlerInterface
{
    public function __construct(
        private Reviews $reviews,
        private JsonResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $page = $request->getQueryParams()['page'] ?? '1';

        return $this->responder->ok($this->reviews->shown() ? $this->reviews->page(PathParameter::id($request), is_numeric($page) ? (int) $page : 1) : ['rating' => null, 'reviews' => [], 'page' => 1, 'pages' => 1]);
    }
}
