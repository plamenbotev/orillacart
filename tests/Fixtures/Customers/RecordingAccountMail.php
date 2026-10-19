<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Customers;

use OrillaCart\Customers\Application\AccountLinks;
use OrillaCart\Customers\Application\AccountMail;

/**
 * Remembers what signing up would have emailed, and makes the links: the account page, log-in, lost password.
 */
final class RecordingAccountMail implements AccountMail, AccountLinks
{
    /** @var list<array{kind: string, to: string, link: ?string}> */
    public array $sent = [];

    public function confirm(string $email, string $firstName, string $link, \DateTimeImmutable $until): void
    {
        $this->sent[] = ['kind' => 'confirm', 'to' => $email, 'link' => $link];
    }

    public function exists(string $email, string $loginUrl, string $lostPasswordUrl): void
    {
        $this->sent[] = ['kind' => 'exists', 'to' => $email, 'link' => null];
    }

    /** The token of the last link sent to the email. */
    public function tokenFor(string $email): string
    {
        foreach (array_reverse($this->sent) as $mail) {
            if ($mail['to'] === $email && $mail['link'] !== null) {
                parse_str((string) parse_url($mail['link'], \PHP_URL_QUERY), $query);

                return \is_string($query['orillacart_account'] ?? null) ? $query['orillacart_account'] : '';
            }
        }

        return '';
    }

    public function confirmUrl(string $token): string
    {
        return 'https://shop.test/account/?orillacart_account=' . $token;
    }

    public function loginUrl(): string
    {
        return 'https://shop.test/wp-login.php';
    }

    public function lostPasswordUrl(): string
    {
        return 'https://shop.test/wp-login.php?action=lostpassword';
    }
}
