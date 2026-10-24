<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Serialization;

use OrillaCart\Platform\I18n\GettextTranslations;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\I18n\Translations;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Words a view keeps in English (a refusal, a label) go out as the site's language has them, to the REST API and
 * to the templates alike.
 */
final readonly class TextNormalizer implements NormalizerInterface
{
    public function __construct(private Translations $translations = new GettextTranslations()) {}

    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        if (!$data instanceof Text) {
            throw new \InvalidArgumentException(\sprintf('Only words can be normalized here, not %s.', get_debug_type($data)));
        }

        return $this->translations->text($data);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Text;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Text::class => true];
    }
}
