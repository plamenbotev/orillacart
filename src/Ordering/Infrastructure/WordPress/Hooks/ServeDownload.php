<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\WordPress\Hooks;

use OrillaCart\Ordering\Application\Downloads;
use OrillaCart\Ordering\Domain\DownloadUnavailable;
use OrillaCart\Shared\Files\ByteRange;
use OrillaCart\Shared\Files\FileDelivery;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\I18n\Translations;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Sends a bought file to whoever follows its secret link (?orillacart_download=TOKEN). PHP reads it from the
 * private store, or hands it to the web server when the shop chose X-Sendfile or X-Accel-Redirect: either way
 * only after the link was checked, since the web server never serves the folder by itself. A browser resuming a
 * download that broke off gets the rest (Range), counted as the same download; it is told how far the download got.
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class ServeDownload
{
    public const string QUERY = 'orillacart_download';
    private const int CHUNK = 1048576;

    public function __construct(
        private Downloads $downloads,
        private FileDelivery $delivery,
        private Translations $translations,
    ) {}

    public function __invoke(): void
    {
        $token = $_GET[self::QUERY] ?? null;
        if (!\is_string($token)) {
            return;
        }

        $range = \is_string($_SERVER['HTTP_RANGE'] ?? null) ? $_SERVER['HTTP_RANGE'] : '';
        try {
            $file = $this->downloads->open(sanitize_key($token), $range, ClientAddress::of($_SERVER));
        } catch (DownloadUnavailable $e) {
            wp_die(esc_html($this->translations->text($e->text)), esc_html__('Download not available', 'orillacart'), ['response' => 403, 'back_link' => true]);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: ' . $file->mimeType);
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $file->filename) . '"; filename*=UTF-8\'\'' . rawurlencode($file->filename));
        header('X-Content-Type-Options: nosniff');
        // A bought file is the seller's, whatever it holds: opened in the browser it runs no script as the shop.
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow');
        $handOff = $this->delivery->handOff($file->key);
        if ($handOff !== null) {
            // The web server sends the file, its length and the part asked for.
            header($handOff);
            exit;
        }
        // Told how far it got when the visitor goes, so a resume goes on from there (Downloads::sentOnly()).
        ignore_user_abort(true);
        $until = self::send($file->path, $range);
        if ($until !== null) {
            $this->downloads->sentOnly(sanitize_key($token), $until);
        }
        exit;
    }

    /**
     * The file, or the one range of it asked for, in pieces, so a large file never fills PHP's memory.
     *
     * @return int|null how far into the file went out, when the visitor went before the end
     */
    private static function send(string $path, string $range): ?int
    {
        $size = (int) filesize($path);
        $part = $range === '' ? null : ByteRange::of($range, $size);
        header('Accept-Ranges: bytes');
        if ($part === false) {
            status_header(416);
            header("Content-Range: bytes */{$size}");

            return null;
        }
        $part ??= new ByteRange(0, $size - 1);
        if ($range !== '' && $part->length() !== $size) {
            status_header(206);
            header("Content-Range: bytes {$part->start}-{$part->end}/{$size}");
        }
        header('Content-Length: ' . (string) max(0, $part->length()));
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        fseek($handle, $part->start);
        $left = $part->length();
        while ($left > 0 && !feof($handle) && connection_status() === CONNECTION_NORMAL) {
            $chunk = fread($handle, min(self::CHUNK, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
            $left -= \strlen($chunk);
        }
        fclose($handle);

        return $left > 0 ? $part->end + 1 - $left : null;
    }
}
