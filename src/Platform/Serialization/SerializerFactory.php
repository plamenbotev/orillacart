<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Serialization;

use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The serializer DTOs go through. JSON field names are the snake_case form of property names,
 * as in the WordPress REST API; #[SerializedName] overrides one, #[Groups] selects fields.
 */
final class SerializerFactory
{
    private ?ClassMetadataFactoryInterface $metadata = null;

    public function create(): Serializer
    {
        // Docblock types first, so a list<SaveOption> parameter is filled with SaveOption objects.
        $reflection = new ReflectionExtractor();
        $types = new PropertyInfoExtractor([$reflection], [new PhpStanExtractor(), $reflection], [], [$reflection], [$reflection]);

        return new Serializer(
            [
                new BackedEnumNormalizer(),
                new DateTimeNormalizer([DateTimeNormalizer::FORMAT_KEY => \DATE_ATOM]),
                new ArrayDenormalizer(),
                new ObjectNormalizer($this->metadata(), $this->nameConverter(), null, $types),
            ],
            [new JsonEncoder()],
        );
    }

    public function nameConverter(): NameConverterInterface
    {
        return new MetadataAwareNameConverter($this->metadata(), new CamelCaseToSnakeCaseNameConverter());
    }

    private function metadata(): ClassMetadataFactoryInterface
    {
        return $this->metadata ??= new ClassMetadataFactory(new AttributeLoader());
    }
}
