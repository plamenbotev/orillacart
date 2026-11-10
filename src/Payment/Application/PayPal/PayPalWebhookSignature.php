<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\PayPal;

/**
 * The five headers PayPal signs a webhook with, checked before anything is asked of PayPal: the certificate on
 * PayPal's own hosts, the one algorithm it uses, a time PayPal could have sent it at. The signature is then
 * verified here, against PayPal's certificate: RSA with SHA-256 over the transmission id, its time, the shop's
 * webhook id and the CRC32 of the body.
 */
final readonly class PayPalWebhookSignature
{
    /** The only algorithm PayPal signs webhooks with. */
    public const string ALGORITHM = 'SHA256withRSA';

    /** PayPal retries a webhook for three days; one older than this is not PayPal's retry. */
    private const int OLDEST = 4 * 86400;
    /** Clocks apart: PayPal's may be a little ahead. */
    private const int AHEAD = 600;

    /** @var array<string, list<string>> where PayPal's certificates are, live and in the sandbox */
    private const array HOSTS = [
        'live' => ['api.paypal.com', 'api-m.paypal.com'],
        'sandbox' => ['api.sandbox.paypal.com', 'api-m.sandbox.paypal.com'],
    ];

    /** @var array<string, string> whom PayPal's certificates are issued to */
    private const array SUBJECTS = [
        'live' => 'messageverificationcerts.paypal.com',
        'sandbox' => 'messageverificationcerts.sandbox.paypal.com',
    ];

    private function __construct(
        public string $transmissionId,
        public string $transmissionTime,
        public string $certificateUrl,
        public string $signature,
    ) {}

    /**
     * The headers when they are in PayPal's shape, else null.
     *
     * @param array<string, string> $headers lower-case names
     */
    public static function of(array $headers, \DateTimeImmutable $now, bool $sandbox): ?self
    {
        $id = $headers['paypal-transmission-id'] ?? '';
        $time = $headers['paypal-transmission-time'] ?? '';
        $url = $headers['paypal-cert-url'] ?? '';
        $signature = $headers['paypal-transmission-sig'] ?? '';
        if (($headers['paypal-auth-algo'] ?? '') !== self::ALGORITHM
            || preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $time) !== 1
            || preg_match('#^https://([a-z.-]{1,64})/v1/notifications/certs/CERT-[A-Za-z0-9-]{1,100}$#', $url, $host) !== 1
            || !\in_array($host[1], self::HOSTS[$sandbox ? 'sandbox' : 'live'], true)
            || preg_match('#^[A-Za-z0-9+/]{100,1400}={0,2}$#', $signature) !== 1) {
            return null;
        }
        try {
            $sent = new \DateTimeImmutable($time);
        } catch (\Exception) {
            return null;
        }
        $age = $now->getTimestamp() - $sent->getTimestamp();
        if ($age > self::OLDEST || $age < -self::AHEAD) {
            return null;
        }

        return new self($id, $time, $url, $signature);
    }

    /**
     * Whether PayPal signed the body for the webhook id: true or false when the certificate is PayPal's and in
     * date; null when it does not look like PayPal's, or PHP has no openssl (the shop then asks PayPal).
     */
    public function verify(string $certificatePem, string $webhookId, string $body, \DateTimeImmutable $now, bool $sandbox): ?bool
    {
        if (!\function_exists('openssl_verify')) {
            return null;
        }
        // openssl warns of what is not a certificate at all: not asked to read that.
        $certificate = str_starts_with(ltrim($certificatePem), '-----BEGIN CERTIFICATE-----') ? openssl_x509_read($certificatePem) : false;
        $details = $certificate === false ? false : openssl_x509_parse($certificate);
        $key = $certificate === false ? false : openssl_pkey_get_public($certificate);
        if (!\is_array($details) || $key === false) {
            return null;
        }
        $subject = \is_array($details['subject'] ?? null) ? ($details['subject']['CN'] ?? null) : null;
        // Expired is not PayPal's any more; one not yet valid could only be PayPal's own new one, a clock apart.
        $to = \is_int($details['validTo_time_t'] ?? null) ? $details['validTo_time_t'] : 0;
        if ($subject !== self::SUBJECTS[$sandbox ? 'sandbox' : 'live'] || $now->getTimestamp() > $to) {
            return null;
        }
        $signature = base64_decode($this->signature, true);
        if ($signature === false) {
            return false;
        }
        $signed = "{$this->transmissionId}|{$this->transmissionTime}|{$webhookId}|" . crc32($body);

        return openssl_verify($signed, $signature, $key, \OPENSSL_ALGO_SHA256) === 1;
    }
}
