<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Http;

use OrillaCart\Ordering\Application\ReceiptHandles;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Security\Authorization;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The receipt token a request to a receipt's action stands for: from its path, or from the cookie a receipt page's
 * handle points to (ReceiptHandles).
 */
final readonly class PresentedReceipt
{
    public function __construct(
        private ReceiptHandles $handles,
        private Authorization $authorization,
    ) {}

    /**
     * @throws HttpException 410 when the page's handle has no token behind it any more
     */
    public function of(ServerRequestInterface $request, string $presented): string
    {
        $kept = $request->getCookieParams()[ReceiptHandles::COOKIE] ?? null;
        $token = $this->handles->token($presented, \is_string($kept) ? $kept : null, $this->authorization->userId());
        if ($token === null) {
            throw new HttpException(410, 'orillacart_receipt_not_kept', 'This page was open for too long. Open the link in your email again.');
        }

        return $token;
    }
}
