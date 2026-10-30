<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\WordPress;

use OrillaCart\Tax\Application\Dto\VatCheck;
use OrillaCart\Tax\Application\VatRegistry;
use Psr\Log\LoggerInterface;

/**
 * Asks VIES, the EU's register, over the WordPress HTTP API. Its answers are kept a day, so the summary that is
 * priced again on every change of the checkout form asks once. When VIES cannot answer for a number, that is kept
 * five minutes: checkout is not held up by a slow or down VIES on every change, and the number is not trusted
 * meanwhile (unavailable charges VAT, VatNumbers).
 */
final readonly class ViesVatRegistry implements VatRegistry
{
    private const string URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';
    /** VIES answers in well under a second when it is up; checkout does not wait long when it is not. */
    private const int TIMEOUT = 4;
    /** How long a number VIES could not check is not asked about again. */
    private const int UNAVAILABLE_SECONDS = 300;

    public function __construct(private LoggerInterface $logger) {}

    public function check(string $prefix, string $number): VatCheck
    {
        $key = 'orillacart_vies_' . substr(hash('sha256', $prefix . $number), 0, 32);
        $kept = get_transient($key);
        if (\is_array($kept) && \is_string($kept['status'] ?? null)) {
            return new VatCheck($kept['status'], $prefix . $number, \is_string($kept['name'] ?? null) ? $kept['name'] : null);
        }
        $response = wp_remote_get(\sprintf(self::URL, rawurlencode($prefix), rawurlencode($number)), ['timeout' => self::TIMEOUT, 'headers' => ['Accept' => 'application/json']]);
        if ($response instanceof \WP_Error) {
            $this->logger->warning('VIES could not be reached: {message}', ['message' => $response->get_error_message()]);

            return $this->unavailable($key, $prefix . $number);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $answer = \is_array($body) && \is_string($body['userError'] ?? null) ? $body['userError'] : '';
        $valid = \is_array($body) && ($body['isValid'] ?? false) === true;
        if (!$valid && !\in_array($answer, ['VALID', 'INVALID', 'INVALID_INPUT'], true)) {
            $this->logger->warning('VIES did not check {number}: {answer}', ['number' => $prefix . $number, 'answer' => $answer === '' ? (string) wp_remote_retrieve_response_code($response) : $answer]);

            return $this->unavailable($key, $prefix . $number);
        }
        $name = \is_array($body) && \is_string($body['name'] ?? null) && trim($body['name']) !== '---' ? trim($body['name']) : null;
        $status = $valid ? VatCheck::VALID : VatCheck::INVALID;
        set_transient($key, ['status' => $status, 'name' => $name], DAY_IN_SECONDS);

        return new VatCheck($status, $prefix . $number, $name);
    }

    private function unavailable(string $key, string $number): VatCheck
    {
        set_transient($key, ['status' => VatCheck::UNAVAILABLE, 'name' => null], self::UNAVAILABLE_SECONDS);

        return new VatCheck(VatCheck::UNAVAILABLE, $number);
    }
}
