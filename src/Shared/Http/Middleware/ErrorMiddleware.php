<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Middleware;

use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
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
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            return $this->responder->error($e->status, $e->errorCode, $e->getMessage(), $e->details);
        } catch (NotFound $e) {
            return $this->responder->error(404, $e->errorCode, $e->getMessage());
        } catch (InvalidFields $e) {
            return $this->responder->error(422, $e->errorCode, $e->getMessage(), ['violations' => $e->violations]);
        } catch (RuleViolation $e) {
            return $this->responder->error(422, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled {exception} on {method} {path}: {message}', [
                'exception' => $e,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'message' => $e->getMessage(),
            ]);

            return $this->responder->error(500, 'orillacart_internal_error', 'Something went wrong. The error has been logged.');
        }
    }
}
