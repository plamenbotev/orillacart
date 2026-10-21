<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure;

use OrillaCart\Integrations\Application\ViewNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/** The serializer the shop's API answers with. */
final readonly class SerializerViewNormalizer implements ViewNormalizer
{
    public function __construct(private NormalizerInterface $normalizer) {}

    public function normalize(object $view): array
    {
        $data = $this->normalizer->normalize($view, 'json');

        return \is_array($data) ? $data : [];
    }
}
