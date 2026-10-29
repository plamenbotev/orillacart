<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Domain\TranslationRuleViolation;

/**
 * The shop's objects in the site's translation service, such as WPML's translation management (design 51): each
 * object's words as a package, registered when the object is saved and removed with it; what translators finish
 * comes back into the translations table, which stays the truth the shop reads. The service's word is taken only
 * when it changed since the service last gave it, so a translation fixed in the shop's own screens stays until
 * the service's translators change theirs.
 */
final readonly class TranslationPackages
{
    public function __construct(
        private TranslationPackageSources $sources,
        private TranslationService $service,
        private ContentTranslations $translations,
    ) {}

    public function active(): bool
    {
        return $this->service->active();
    }

    /** The object was saved: its package gets its words as they are now. */
    public function changed(string $kind, int $id): void
    {
        $source = $this->sources->get($kind);
        if ($source === null || !$this->service->active()) {
            return;
        }
        $package = $source->package($id);
        if ($package === null || $package->strings === []) {
            $this->service->remove($kind, $id, $source->title());

            return;
        }
        $this->service->register($package, $source->title());
        $this->send($package, $source);
    }

    /**
     * Gives the service the translations the shop's screens changed: a word the shop has that differs from what
     * the service last gave, where the service's own has not changed since. One its translators changed since
     * is theirs, taken at the next pull, never overwritten.
     */
    private function send(TranslationPackage $package, TranslationPackageSource $source): void
    {
        $kindTitle = $source->title();
        $theirs = $this->service->translated($package, $kindTitle);
        $kept = $source instanceof KeepsItsTranslations ? $source->translationsOf($package) : null;
        foreach ($package->strings as $string) {
            $ours = $kept === null ? ($this->translations->of($string->type, [$string->id])[$string->id][$string->field] ?? []) : ($kept[$string->key()] ?? []);
            $copies = $this->translations->serviceCopies($string->type, $string->id)[$string->field] ?? [];
            $sent = [];
            foreach ($ours as $locale => $value) {
                $their = $theirs[$string->key()][$locale] ?? null;
                $copy = $copies[$locale] ?? null;
                if ($value === $their || ($their !== null && $their !== $copy)) {
                    continue;
                }
                $this->service->translate($package, $string, $locale, $value, $kindTitle);
                $sent[$locale] = $value;
            }
            if ($sent !== []) {
                $this->translations->keepServiceCopies($string->type, $string->id, [$string->field => $sent]);
            }
        }
    }

    public function removed(string $kind, int $id): void
    {
        $source = $this->sources->get($kind);
        if ($source !== null && $this->service->active()) {
            $this->service->remove($kind, $id, $source->title());
        }
    }

    /**
     * Registers every object of every kind, as when the service was added to a shop that has them.
     *
     * @return int how many packages
     */
    public function registerAll(): int
    {
        if (!$this->service->active()) {
            return 0;
        }
        $count = 0;
        foreach ($this->sources->all() as $source) {
            foreach ($source->ids() as $id) {
                $package = $source->package($id);
                if ($package !== null && $package->strings !== []) {
                    $this->service->register($package, $source->title());
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * Takes what the service's translators finished into the translations table, for every object. A translation
     * the shop would refuse (too long, a language the site does not offer) is left out, with the rest of the object's.
     *
     * @return array{int, int} the objects whose translations changed, and those refused
     */
    public function pull(): array
    {
        if (!$this->service->active()) {
            return [0, 0];
        }
        $changed = 0;
        $refused = 0;
        foreach ($this->sources->all() as $source) {
            foreach ($source->ids() as $id) {
                $package = $source->package($id);
                if ($package === null || $package->strings === []) {
                    continue;
                }
                $found = $this->service->translated($package, $source->title());
                if ($source instanceof KeepsItsTranslations) {
                    [$took, $refusedHere] = $this->keep($source, $package, $found);
                    $changed += $took;
                    $refused += $refusedHere;
                    continue;
                }
                // By type, id, field and locale: an attribute set's package has its options' words too.
                $objects = [];
                foreach ($package->strings as $string) {
                    if (isset($found[$string->key()])) {
                        $objects[$string->type][$string->id][$string->field] = $found[$string->key()];
                    }
                }
                foreach ($objects as $type => $byId) {
                    foreach ($byId as $objectId => $fields) {
                        $outcome = $this->take($type, $objectId, $fields);
                        $changed += (int) ($outcome === true);
                        $refused += (int) ($outcome === null);
                    }
                }
            }
        }

        return [$changed, $refused];
    }

    /**
     * The service's words a source keeps itself, those changed since the service last gave them.
     *
     * @param array<string, array<string, string>> $found by key and locale
     *
     * @return array{int, int} the words taken, and those the shop refused
     */
    private function keep(KeepsItsTranslations $source, TranslationPackage $package, array $found): array
    {
        $took = 0;
        $refused = 0;
        foreach ($package->strings as $string) {
            $copies = $this->translations->serviceCopies($string->type, $string->id)[$string->field] ?? [];
            foreach ($found[$string->key()] ?? [] as $locale => $value) {
                if (($copies[$locale] ?? null) === $value) {
                    continue;
                }
                if (!$source->keep($package, $string, $locale, $value)) {
                    ++$refused;
                    continue;
                }
                $this->translations->keepServiceCopies($string->type, $string->id, [$string->field => [$locale => $value]]);
                ++$took;
            }
        }

        return [$took, $refused];
    }

    /**
     * @param array<string, array<string, string>> $fields by field and locale, from the service
     *
     * @return bool|null whether the object's translations changed; null when the shop refused them
     */
    private function take(string $type, int $id, array $fields): ?bool
    {
        // Only what the service changed since it last gave it: the rest may have been fixed in the shop since.
        $copies = $this->translations->serviceCopies($type, $id);
        $given = [];
        foreach ($fields as $field => $locales) {
            foreach ($locales as $locale => $value) {
                if (($copies[$field][$locale] ?? null) !== $value) {
                    $given[$field][$locale] = $value;
                }
            }
        }
        $existing = $this->translations->of($type, [$id])[$id] ?? [];
        $merged = [];
        foreach ($given as $field => $locales) {
            $now = [...($existing[$field] ?? []), ...$locales];
            ksort($now);
            $before = $existing[$field] ?? [];
            ksort($before);
            if ($now !== $before) {
                $merged[$field] = $now;
            }
        }
        if ($merged !== []) {
            try {
                $this->translations->write($type, $id, $this->translations->check($type, $merged));
            } catch (TranslationRuleViolation) {
                return null;
            }
        }
        if ($given !== []) {
            $this->translations->keepServiceCopies($type, $id, $given);
        }

        return $merged !== [];
    }
}
