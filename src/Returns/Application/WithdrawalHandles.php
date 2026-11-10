<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application;

/**
 * What the withdrawal page gives its form in place of the emailed link's token: kept-NUMBER, which says only which
 * order the page is for. The token itself stays where scripts cannot read it, in a cookie the browser sends to the
 * withdrawal's actions (COOKIE): an analytics tag or a browser extension reading the page never holds what shows
 * the order's items and withdraws from it. As a receipt's (ReceiptHandles).
 */
final readonly class WithdrawalHandles
{
    /** The cookie the link's token is kept in for the withdrawal's actions, beside the page's own. */
    public const string COOKIE = 'orillacart_withdrawal_actions';

    /** The handle of the order a token is for; null for what is no token. */
    public static function of(#[\SensitiveParameter] string $token): ?string
    {
        return preg_match('/^(\d{1,18})\./', $token, $parts) === 1 ? "kept-{$parts[1]}" : null;
    }

    /**
     * The token a form presented: itself, or for a handle the one the browser keeps for that same order (another
     * order's link opened since, in another tab, leaves this one without).
     *
     * @param string|null $kept the token the browser sent in COOKIE
     *
     * @return string|null null for a handle the browser holds no token for
     */
    public static function token(string $presented, #[\SensitiveParameter] ?string $kept): ?string
    {
        if (preg_match('/^kept-\d{1,18}$/', $presented) !== 1) {
            return $presented;
        }

        return $kept !== null && self::of($kept) === $presented ? $kept : null;
    }
}
