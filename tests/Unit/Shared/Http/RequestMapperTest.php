<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\Http;

use Nyholm\Psr7\ServerRequest;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Serialization\ValidatorFactory;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use PHPUnit\Framework\TestCase;

final class RequestMapperTest extends TestCase
{
    private const array VALID = ['currency' => 'USD', 'price_decimals' => 2, 'weight_unit' => 'lb', 'dimension_unit' => 'in'];

    public function testAValidBodyBecomesTheDto(): void
    {
        $dto = $this->mapper()->body($this->request(self::VALID + ['_wpnonce' => 'abc']), UpdateSettings::class);

        self::assertEquals(new UpdateSettings('USD', 2, 'lb', 'in'), $dto);
    }

    public function testWrongTypesAreReportedPerField(): void
    {
        self::assertSame(
            [['field' => 'price_decimals', 'message' => 'This value should be of type int.']],
            $this->violations(['price_decimals' => 'two'] + self::VALID),
        );
    }

    public function testMissingFieldsAreReportedPerField(): void
    {
        $violations = $this->violations(['currency' => 'USD']);

        self::assertSame(['price_decimals', 'weight_unit', 'dimension_unit'], array_column($violations, 'field'));
    }

    public function testUnexpectedFieldsAreRefused(): void
    {
        self::assertSame(
            [['field' => 'is_admin', 'message' => 'This field is not expected.']],
            $this->violations(self::VALID + ['is_admin' => true]),
        );
    }

    public function testValidationRulesAreReportedUnderTheJsonName(): void
    {
        $violations = $this->violations(['currency' => 'usd', 'price_decimals' => 9] + self::VALID);

        self::assertSame(['currency', 'price_decimals'], array_column($violations, 'field'));
        self::assertSame('Use a three-letter currency code such as EUR.', $violations[0]['message']);
    }

    public function testABodyThatIsNotJsonIsABadRequest(): void
    {
        $request = new ServerRequest('PUT', '/settings', [], '{not json');

        try {
            $this->mapper()->body($request, UpdateSettings::class);
            self::fail('Expected a bad request.');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array{field: string, message: string}>
     */
    private function violations(array $body): array
    {
        try {
            $this->mapper()->body($this->request($body), UpdateSettings::class);
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
            $violations = $e->details['violations'] ?? null;
            self::assertIsArray($violations);

            /** @var list<array{field: string, message: string}> $violations */
            return $violations;
        }
        self::fail('Expected the input to be refused.');
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): ServerRequest
    {
        return new ServerRequest('PUT', '/settings', ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR))
            ->withParsedBody($body);
    }

    private function mapper(): RequestMapper
    {
        $serializers = new SerializerFactory();

        return new RequestMapper($serializers->create(), new ValidatorFactory()->create(), $serializers->nameConverter());
    }
}
