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
 * A page of a product's published reviews, as its page shows them to anyone: an empty one for a product that is
 * not published or has a password, as for one that does not exist.
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
        // Digits only, as many as a page number has: Reviews::page() takes up to its last page.
        $page = \is_string($page) && ctype_digit($page) && \strlen($page) <= 9 ? (int) $page : 1;

        return $this->responder->ok($this->reviews->shown() ? $this->reviews->page(PathParameter::id($request), $page, forAnyone: true) : ['rating' => null, 'reviews' => [], 'page' => 1, 'pages' => 1]);
    }
}
