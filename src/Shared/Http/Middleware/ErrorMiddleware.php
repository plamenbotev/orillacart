<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Middleware;

use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Shared\I18n\Translations;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Outermost middleware: turns every exception into a response. Messages of unexpected
 * errors are logged, never shown, so internals do not leak to visitors.
 */
final readonly class ErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JsonResponder $responder,
        private LoggerInterface $logger,
        private Translations $translations,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            $details = $e->details;
            if (isset($details['violations']) && \is_array($details['violations'])) {
                /** @var list<array{field: string, message: string|Text}> $violations */
                $violations = $details['violations'];
                $details['violations'] = $this->violations($violations);
            }

            return $this->responder->error($e->status, $e->errorCode, $this->translations->text($e->text), $details);
        } catch (NotFound $e) {
            return $this->responder->error(404, $e->errorCode, $this->translations->text($e->text));
        } catch (InvalidFields $e) {
            return $this->responder->error(422, $e->errorCode, $this->translations->text($e->text), ['violations' => $this->violations($e->violations)]);
        } catch (RuleViolation $e) {
            return $this->responder->error(422, $e->errorCode, $this->translations->text($e->text));
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled {exception} on {method} {path}: {message}', [
                'exception' => $e,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'message' => $e->getMessage(),
            ]);

            return $this->responder->error(500, 'orillacart_internal_error', $this->translations->text('Something went wrong. The error has been logged.'));
        }
    }

    /**
     * Words of the shop's rules are translated; a validation message is already (RequestMapper).
     *
     * @param list<array{field: string, message: string|Text}> $violations
     *
     * @return list<array{field: string, message: string}>
     */
    private function violations(array $violations): array
    {
        return array_map(fn(array $violation): array => ['field' => $violation['field'], 'message' => $violation['message'] instanceof Text ? $this->translations->text($violation['message']) : $violation['message']], $violations);
    }
}
