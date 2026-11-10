<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * Who an email address reaches, for limits and caps: one person's mailbox, however it is written. Lowercased,
 * without a +tag (ada+shop@example.com is ada@example.com), and for Gmail without the dots it ignores
 * (a.da@googlemail.com is ada@gmail.com). Mail still goes to the address as written.
 */
final class EmailAddress
{
    private const array GMAIL = ['gmail.com', 'googlemail.com'];

    public static function key(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $plus = strpos($local, '+');
        if ($plus !== false && $plus > 0) {
            $local = substr($local, 0, $plus);
        }
        if (\in_array($domain, self::GMAIL, true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }
}
