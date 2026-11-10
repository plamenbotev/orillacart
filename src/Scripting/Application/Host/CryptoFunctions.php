<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Host;

use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;

/**
 * `crypto`: what a script needs to tell a provider's signed message from a made-up one: the HMAC a provider signs
 * with, and a comparison that takes as long whatever the strings hold, so timing it tells nothing of the secret.
 */
final readonly class CryptoFunctions implements HostNamespace
{
    /** The hashes providers sign webhooks with; nothing weaker. */
    private const array ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    /** How a signature comes written. */
    private const array ENCODINGS = ['hex', 'base64'];

    public function functions(): array
    {
        // Each only works a value out: none changes the shop or reaches outside it.
        return HostFunction::reading([
            new HostFunction('crypto.hmac', static function (Context $c, mixed $algorithm, mixed $data, mixed $key, mixed $encoding = 'hex'): string {
                $algorithm = strtolower(Ops::text($algorithm));
                $encoding = strtolower(Ops::text($encoding));
                if (!\in_array($algorithm, self::ALGORITHMS, true)) {
                    throw new ScriptError(\sprintf('crypto.hmac takes %s, not "%s"', implode(', ', self::ALGORITHMS), $algorithm));
                }
                if (!\in_array($encoding, self::ENCODINGS, true)) {
                    throw new ScriptError(\sprintf('crypto.hmac writes hex or base64, not "%s"', $encoding));
                }
                $key = Ops::text($key);
                if ($key === '') {
                    throw new ScriptError('crypto.hmac needs a key: a signature with none proves nothing');
                }
                $mac = hash_hmac($algorithm, Ops::text($data), $key, true);

                return $encoding === 'hex' ? bin2hex($mac) : base64_encode($mac);
            }, 3, 4, 'crypto.hmac("sha256", data, key, "hex"?): the HMAC of the data with the key (sha256, sha384 or sha512), written as hex or base64'),
            new HostFunction('crypto.equals', static fn(Context $c, mixed $known, mixed $given): bool => hash_equals(Ops::text($known), Ops::text($given)), 2, 2, 'crypto.equals(expected, given): whether two strings are the same, in a time that tells nothing of where they differ: compare signatures with it, never with =='),
        ]);
    }
}
