<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageState;
use Psr\Log\LoggerInterface;

/**
 * `{{ asset('portal.js') }}` in an extension's template (design 59): the file under assets/ in the package, served
 * by the web server, printed as a script tag (deferred), a stylesheet link, or the URL of an SVG. Printed once per
 * page however many templates ask for it. Only an enabled extension granted `assets` gets its files served; one
 * that is not, or names a file it does not have, prints nothing and the log says why.
 */
final class ExtensionAssets
{
    public const string FOLDER = 'assets/';
    public const string PERMISSION = 'assets';

    /** @var array<string, true> what this request printed already, by extension and file */
    private array $printed = [];

    public function __construct(private readonly PackageRepository $packages, private readonly AssetFiles $files, private readonly LoggerInterface $logger) {}

    /** The tag, or the URL for an SVG; '' when the file cannot be served. */
    public function tag(string $extension, string $name): string
    {
        $once = !str_ends_with($name, '.svg');
        if ($once && isset($this->printed[$extension . '/' . $name])) {
            return '';
        }
        $url = $this->url($extension, $name);
        if ($url === null) {
            return '';
        }
        $escaped = htmlspecialchars($url, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        if (!$once) {
            return $escaped;
        }
        $this->printed[$extension . '/' . $name] = true;

        return str_ends_with($name, '.css')
            ? \sprintf('<link rel="stylesheet" href="%s">', $escaped)
            : \sprintf('<script src="%s" defer></script>', $escaped);
    }

    /** The URL the file is served at; null when it cannot be. */
    public function url(string $extension, string $name): ?string
    {
        $package = $this->packages->find($extension);
        if ($package === null || $package->state() !== PackageState::Enabled) {
            $this->logger->warning('The extension "{extension}" is not enabled: its asset {name} is not served.', ['extension' => $extension, 'name' => $name]);

            return null;
        }
        if (!\in_array(self::PERMISSION, $package->granted(), true)) {
            $this->logger->warning('The extension "{extension}" was not granted "assets": its asset {name} is not served.', ['extension' => $extension, 'name' => $name]);

            return null;
        }
        $content = $package->runningFile(self::FOLDER . ltrim($name, '/'));
        if ($content === null) {
            $this->logger->warning('The extension "{extension}" has no file assets/{name}: nothing is served for it.', ['extension' => $extension, 'name' => $name]);

            return null;
        }

        return $this->files->publish($extension, ltrim($name, '/'), $content);
    }

    /** Forgets the extension's published files: it was uninstalled. */
    public function forget(string $extension): void
    {
        $this->files->forget($extension);
    }

    /** Forgets what was printed: the next page starts afresh (one request renders one page; tests render several). */
    public function reset(): void
    {
        $this->printed = [];
    }
}
