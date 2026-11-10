<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Http;

use OrillaCart\Returns\Application\WithdrawalHandles;
use OrillaCart\Shared\Http\HttpException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The link's token a request to a withdrawal's action stands for: the one it sent, or the one in the cookie the
 * withdrawal page's handle points to (WithdrawalHandles).
 */
final readonly class PresentedWithdrawal
{
    /**
     * @throws HttpException 410 when the page's handle has no token behind it any more
     */
    public static function of(ServerRequestInterface $request, #[\SensitiveParameter] string $presented): string
    {
        $kept = $request->getCookieParams()[WithdrawalHandles::COOKIE] ?? null;

        return WithdrawalHandles::token($presented, \is_string($kept) ? $kept : null)
            ?? throw new HttpException(410, 'orillacart_withdrawal_not_kept', 'This page was open for too long. Open the link in your email again.');
    }
}
