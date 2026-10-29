<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Notifications\Domain\EmailTemplate;
use OrillaCart\Notifications\Domain\EmailTemplateRepository;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\View\TemplateEditing;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\KeepsItsTranslations;
use OrillaCart\Translations\Application\TranslationPackageSource;
use Psr\Clock\ClockInterface;

/**
 * An email template the shop edited, for the translation service: its source for every language, translated
 * into a template of each language. A template not edited is the plugin's, whose words its own translations
 * carry. A translation the template engine refuses is not kept.
 */
final readonly class EmailTemplatePackages implements TranslationPackageSource, KeepsItsTranslations
{
    public const string KIND = 'email_template';

    public function __construct(
        private EmailTemplateRepository $templates,
        private TemplateEditing $editing,
        private ClockInterface $clock,
    ) {}

    /** A template's id among the packages: a number for its name, the same on every site. */
    public static function id(string $file): int
    {
        return crc32($file) & 0x7FFFFFFF;
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart email template';
    }

    public function package(int $id): ?TranslationPackage
    {
        $template = $this->edited($id);

        return $template === null ? null : TranslationPackage::of(self::KIND, $id, self::label($template->name()), [
            new PackageString(self::KIND, $id, 'source', $template->source(), Text::of('Template, with its subject'), true),
        ]);
    }

    public function ids(): iterable
    {
        foreach ($this->templates->all() as $template) {
            if ($template->locale() === EmailTemplate::EVERY_LANGUAGE) {
                yield self::id($template->name());
            }
        }
    }

    public function translationsOf(TranslationPackage $package): array
    {
        $file = $this->edited($package->id)?->name();
        $translations = [];
        foreach ($this->templates->all() as $template) {
            if ($template->name() === $file && $template->locale() !== EmailTemplate::EVERY_LANGUAGE) {
                $translations[$package->strings[0]->key()][$template->locale()] = $template->source();
            }
        }

        return $translations;
    }

    public function keep(TranslationPackage $package, PackageString $string, string $locale, string $value): bool
    {
        $file = $this->edited($package->id)?->name();
        if ($file === null) {
            return false;
        }
        try {
            $this->editing->check($file, $value);
            $template = $this->templates->find($file, $locale);
            if ($template === null) {
                $template = new EmailTemplate($file, $locale, $value, $this->clock->now(), null);
            } else {
                $template->change($value, $this->clock->now(), null);
            }
            $this->templates->save($template);
        } catch (RuleViolation) {
            return false;
        }

        return true;
    }

    private function edited(int $id): ?EmailTemplate
    {
        foreach ($this->templates->all() as $template) {
            if ($template->locale() === EmailTemplate::EVERY_LANGUAGE && self::id($template->name()) === $id) {
                return $template;
            }
        }

        return null;
    }

    /** "emails/order-shipped.html.twig" as "order shipped". */
    private static function label(string $file): string
    {
        return str_replace('-', ' ', (string) preg_replace('#^emails/|\.html\.twig$#', '', $file));
    }
}
