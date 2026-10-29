<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Infrastructure\WordPress;

use OrillaCart\Shared\WordPress\CliCommand;
use OrillaCart\Translations\Application\TranslationPackages;

/**
 * Hands the shop's words to the site's translation service (WPML's string packages), and takes its translations back.
 */
final readonly class TranslationsCommand implements CliCommand
{
    public function __construct(private TranslationPackages $packages) {}

    public static function name(): string
    {
        return 'orillacart translations';
    }

    /**
     * Registers every carrier, payment method, form, filter, attribute set, product's attributes and edited email template as a package.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function register(array $args, array $options): void
    {
        if (!$this->packages->active()) {
            \WP_CLI::error('The site has no translation service: WPML with String Translation, on a site in several languages.');
        }
        \WP_CLI::success(\sprintf('Registered %d packages.', $this->packages->registerAll()));
    }

    /**
     * Takes what the translation service's translators finished into the shop's translations.
     *
     * @param list<string>               $args
     * @param array<string, string|bool> $options
     */
    public function pull(array $args, array $options): void
    {
        if (!$this->packages->active()) {
            \WP_CLI::error('The site has no translation service: WPML with String Translation, on a site in several languages.');
        }
        [$changed, $refused] = $this->packages->pull();
        if ($refused > 0) {
            \WP_CLI::warning(\sprintf('%d objects\' translations were refused: too long, or in a language the site does not offer.', $refused));
        }
        \WP_CLI::success(\sprintf('Took translations for %d objects.', $changed));
    }
}
