<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Input;

use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Middleware\CsrfMiddleware;
use OrillaCart\Shared\I18n\Translations;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turns a request body into a command or query DTO and validates it, so actions only ever see valid,
 * typed input. Every problem is reported at once as a 422 with one entry per field.
 */
final readonly class RequestMapper
{
    /** Query parameters WordPress adds to REST requests. */
    private const array WORDPRESS_PARAMETERS = ['_locale', 'rest_route', '_envelope', '_fields', '_wpnonce'];

    /** A format whose values the serializer reads as text, casting "2" to 2 and "true" to true, as CSV does. */
    private const string TEXT_FORMAT = 'csv';

    public function __construct(
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
        private NameConverterInterface $names,
        private Translations $translations,
    ) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function body(ServerRequestInterface $request, string $class): object
    {
        $data = $request->getParsedBody();
        if ($data === null && $request->getBody()->getSize() !== 0) {
            throw HttpException::badRequest('The request body is not valid JSON.');
        }
        $data = \is_array($data) ? $data : [];
        unset($data[CsrfMiddleware::FIELD]);

        return $this->map($data, $class);
    }

    /**
     * Turns the query string into a query DTO and validates it. Query values are all text, so numbers and
     * booleans are read from it; WordPress's own parameters (_locale, rest_route) are left out.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function query(ServerRequestInterface $request, string $class): object
    {
        $data = array_filter(
            $request->getQueryParams(),
            static fn(mixed $value, int|string $name): bool => !\in_array($name, self::WORDPRESS_PARAMETERS, true) && $value !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        return $this->map($data, $class, self::TEXT_FORMAT);
    }

    /**
     * @template T of object
     *
     * @param array<array-key, mixed> $data
     * @param class-string<T>         $class
     *
     * @return T
     */
    public function map(array $data, string $class, string $format = 'json'): object
    {
        try {
            $dto = $this->denormalizer->denormalize($data, $class, $format, [
                DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS => true,
                AbstractObjectNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
            ]);
        } catch (PartialDenormalizationException $e) {
            throw HttpException::invalidInput(array_map(
                fn(NotNormalizableValueException $error): array => $this->violation((string) $error->getPath(), $this->translations->text(self::typeMessage($error))),
                array_values($e->getErrors()),
            ));
        } catch (ExtraAttributesException $e) {
            throw HttpException::invalidInput(array_map(
                fn(string $field): array => $this->violation($field, $this->translations->text('This field is not expected.')),
                array_values(array_map(static fn(mixed $attribute): string => \is_string($attribute) ? $attribute : get_debug_type($attribute), $e->getExtraAttributes())),
            ));
        } catch (MissingConstructorArgumentsException $e) {
            throw HttpException::invalidInput(array_map(
                fn(string $field): array => $this->violation($field, $this->translations->text('This field is missing.')),
                array_values($e->getMissingConstructorArguments()),
            ));
        }
        if (!$dto instanceof $class) {
            throw new \LogicException(\sprintf('Denormalizing into %s returned %s.', $class, get_debug_type($dto)));
        }

        $violations = [];
        foreach ($this->validator->validate($dto) as $violation) {
            // Symfony's English template, translated as the site's language has it, the values put in after.
            $violations[] = $this->violation($violation->getPropertyPath(), $this->translations->message($violation->getMessageTemplate(), $violation->getParameters(), $violation->getPlural()));
        }
        if ($violations !== []) {
            throw HttpException::invalidInput($violations);
        }

        return $dto;
    }

    /**
     * Reports a field under the name the client sent, not the PHP property name: price_decimals, items[0].product_id.
     *
     * @return array{field: string, message: string}
     */
    private function violation(string $path, string $message): array
    {
        $field = (string) preg_replace_callback(
            '/[A-Za-z_][A-Za-z0-9_]*/',
            fn(array $segment): string => $this->names->normalize($segment[0]),
            $path,
        );

        return ['field' => $field, 'message' => $message];
    }

    private static function typeMessage(NotNormalizableValueException $error): Text
    {
        $expected = $error->getExpectedTypes() ?? [];

        return $expected === []
            ? new Text('This value is not valid.')
            : Text::of('This value should be of type %s.', implode('|', $expected));
    }
}
