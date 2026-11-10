<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;

/**
 * Webhooks as PayPal sends them: its five headers, signed with a key whose certificate the fake PayPal serves.
 */
final class PayPalNotifications
{
    public const string CERTIFICATE_URL = 'https://api.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-test';

    private static ?\OpenSSLAsymmetricKey $key = null;
    private static string $certificate = '';
    private static int $sent = 0;

    /**
     * A notification of an event, signed for the webhook id, and made known to the shop's fake PayPal.
     *
     * @param array<string, mixed>  $resource
     * @param array<string, string> $headers headers to put in place of the signed ones
     */
    public static function signed(TestShop $shop, string $type, array $resource, string $webhookId = 'WH-1', array $headers = []): Notification
    {
        return self::of($shop, (string) json_encode(['id' => 'WH-EVENT-' . (self::$sent + 1), 'event_type' => $type, 'resource' => $resource]), $webhookId, $headers);
    }

    /**
     * A body as PayPal sends it, signed.
     *
     * @param array<string, string> $headers headers to put in place of the signed ones
     */
    public static function of(TestShop $shop, string $body, string $webhookId = 'WH-1', array $headers = []): Notification
    {
        $id = 'T-' . ++self::$sent;
        $time = $shop->clock->now()->format('Y-m-d\TH:i:s\Z');
        $shop->payPalApi->certificates[self::CERTIFICATE_URL] = self::certificate();

        return new Notification($headers + [
            'paypal-transmission-id' => $id,
            'paypal-transmission-time' => $time,
            'paypal-transmission-sig' => self::sign("{$id}|{$time}|{$webhookId}|" . crc32($body)),
            'paypal-cert-url' => self::CERTIFICATE_URL,
            'paypal-auth-algo' => 'SHA256withRSA',
        ], $body);
    }

    /** The certificate PayPal signs with, as PEM. */
    public static function certificate(): string
    {
        self::keys();

        return self::$certificate;
    }

    /** A signature over the text with PayPal's key, base64 as in the header. */
    public static function sign(string $text): string
    {
        $signature = '';
        openssl_sign($text, $signature, self::keys(), \OPENSSL_ALGO_SHA256);

        return base64_encode(\is_string($signature) ? $signature : '');
    }

    private static function keys(): \OpenSSLAsymmetricKey
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('openssl could not make a key');
        }
        // openssl_csr_new takes the key by reference: given a copy, the key itself stays what it is.
        $copy = $key;
        $request = openssl_csr_new(['commonName' => 'messageverificationcerts.paypal.com'], $copy, ['digest_alg' => 'sha256']);
        $signed = $request instanceof \OpenSSLCertificateSigningRequest ? openssl_csr_sign($request, null, $key, 3650, ['digest_alg' => 'sha256']) : false;
        $pem = '';
        if (!$signed instanceof \OpenSSLCertificate || !openssl_x509_export($signed, $pem)) {
            throw new \RuntimeException('openssl could not make a certificate');
        }
        self::$certificate = \is_string($pem) ? $pem : '';

        return self::$key = $key;
    }
}
