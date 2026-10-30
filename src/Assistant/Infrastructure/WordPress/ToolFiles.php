<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

/**
 * The file a tool sends in place of an upload, written to a temporary file: base64 contents, or an http(s)
 * address the shop fetches with WordPress's safe HTTP, which refuses the site's own network.
 */
final readonly class ToolFiles
{
    /** The most a tool's file may hold. */
    public const int MOST = 100 * 1024 * 1024;

    /**
     * The most base64 a call may carry: about 15 MB of file. The whole request is in memory, and the file again
     * decoded, so a larger one comes as an address the shop fetches to disk.
     */
    public const int MOST_BASE64 = 20 * 1024 * 1024;

    /**
     * @param array<string, mixed> $arguments the tool's file_base64 or file_url
     *
     * @return string the temporary file; the caller deletes it
     *
     * @throws \InvalidArgumentException with what the model must correct
     */
    public function write(array $arguments): string
    {
        $base64 = $arguments['file_base64'] ?? null;
        $url = $arguments['file_url'] ?? null;
        if (\is_string($base64) === \is_string($url)) {
            throw new \InvalidArgumentException('Give the file as file_base64 or as file_url, one of them.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'orillacart');
        if ($temporary === false) {
            throw new \RuntimeException('No temporary file for the tool\'s file.');
        }
        try {
            if (\is_string($base64)) {
                self::decode($base64, $temporary);
            } elseif (\is_string($url)) {
                self::fetch($url, $temporary);
            }
        } catch (\Throwable $e) {
            unlink($temporary);

            throw $e;
        }

        return $temporary;
    }

    private static function decode(string $base64, string $temporary): void
    {
        if (\strlen($base64) > self::MOST_BASE64) {
            throw new \InvalidArgumentException('file_base64 is larger than 20 MB, too much to send in a call: put the file where the shop can fetch it and give its address as file_url (up to 100 MB).');
        }
        $contents = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);
        if ($contents === false || $contents === '') {
            throw new \InvalidArgumentException('file_base64 is not base64, or is empty.');
        }
        file_put_contents($temporary, $contents);
    }

    private static function fetch(string $url, string $temporary): void
    {
        $scheme = wp_parse_url($url, \PHP_URL_SCHEME);
        if (!\in_array(\is_string($scheme) ? strtolower($scheme) : '', ['http', 'https'], true)) {
            throw new \InvalidArgumentException('file_url must be an http or https address.');
        }
        $response = wp_safe_remote_get($url, [
            'timeout' => 60,
            'stream' => true,
            'filename' => $temporary,
            'limit_response_size' => self::MOST + 1,
            'redirection' => 3,
        ]);
        if (is_wp_error($response)) {
            throw new \InvalidArgumentException('The shop could not fetch file_url: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            throw new \InvalidArgumentException("file_url answered {$status}.");
        }
        clearstatcache(true, $temporary);
        $size = filesize($temporary);
        if ($size === false || $size === 0) {
            throw new \InvalidArgumentException('file_url gave an empty file.');
        }
        if ($size > self::MOST) {
            throw new \InvalidArgumentException('The file is larger than 100 MB.');
        }
    }
}
