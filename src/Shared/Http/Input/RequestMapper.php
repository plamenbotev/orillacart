<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Input;

use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Middleware\CsrfMiddleware;
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
    public function __construct(
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
        private NameConverterInterface $names,
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
     * @template T of object
     *
     * @param array<array-key, mixed> $data
     * @param class-string<T>         $class
     *
     * @return T
     */
    public function map(array $data, string $class): object
    {
        try {
            $dto = $this->denormalizer->denormalize($data, $class, 'json', [
                DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS => true,
                AbstractObjectNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
            ]);
        } catch (PartialDenormalizationException $e) {
            throw HttpException::invalidInput(array_map(
                fn(NotNormalizableValueException $error): array => $this->violation((string) $error->getPath(), self::typeMessage($error)),
                array_values($e->getErrors()),
            ));
        } catch (ExtraAttributesException $e) {
            throw HttpException::invalidInput(array_map(
                fn(string $field): array => $this->violation($field, 'This field is not expected.'),
                array_values(array_map(static fn(mixed $attribute): string => \is_string($attribute) ? $attribute : get_debug_type($attribute), $e->getExtraAttributes())),
            ));
        } catch (MissingConstructorArgumentsException $e) {
            throw HttpException::invalidInput(array_map(
                fn(string $field): array => $this->violation($field, 'This field is missing.'),
                array_values($e->getMissingConstructorArguments()),
            ));
        }
        if (!$dto instanceof $class) {
            throw new \LogicException(\sprintf('Denormalizing into %s returned %s.', $class, get_debug_type($dto)));
        }

        $violations = [];
        foreach ($this->validator->validate($dto) as $violation) {
            $violations[] = $this->violation($violation->getPropertyPath(), (string) $violation->getMessage());
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

    private static function typeMessage(NotNormalizableValueException $error): string
    {
        $expected = $error->getExpectedTypes() ?? [];

        return $expected === []
            ? 'This value is not valid.'
            : \sprintf('This value should be of type %s.', implode('|', $expected));
    }
}
