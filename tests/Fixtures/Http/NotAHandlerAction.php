<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Http;

use OrillaCart\Shared\Http\Routing\Route;

#[Route('/broken', methods: ['GET'], capability: Route::PUBLIC)]
final class NotAHandlerAction
{
    public function __invoke(): void {}
}
