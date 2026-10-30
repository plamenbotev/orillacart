<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

use OrillaCart\Integrations\Application\Dto\AuthenticatedKey;
use OrillaCart\Integrations\Application\Dto\AuthorizationRequest;
use OrillaCart\Integrations\Application\Dto\OAuthClientView;
use OrillaCart\Integrations\Domain\ApiKeyRepository;
use OrillaCart\Integrations\Domain\ApiKeyScope;
use OrillaCart\Integrations\Domain\OAuthClient;
use OrillaCart\Integrations\Domain\OAuthClientRepository;
use OrillaCart\Integrations\Domain\OAuthError;
use OrillaCart\Integrations\Domain\OAuthToken;
use OrillaCart\Integrations\Domain\OAuthTokenRepository;
use Psr\Clock\ClockInterface;

/**
 * The shop's OAuth 2.1 server, for AI assistants whose connectors log in instead of taking a key (claude.ai,
 * ChatGPT). It protects one resource, the MCP endpoint:
 * - clients are named by a client ID metadata document (an https address) or register themselves (RFC 7591);
 *   both are public clients and prove themselves with PKCE (S256), never a secret;
 * - the owner says yes on a screen in wp-admin; the yes is an API key under their name with the scope they chose,
 *   listed and revoked with the others;
 * - a code is good once for ten minutes; an access token for an hour, at the MCP endpoint only; a refresh token
 *   for thirty days, and once: it gives a new pair. The one it replaced, presented again, ends the pair: someone
 *   else has it (OAuth 2.1 §6.1);
 * - a client that registered itself and was never connected is forgotten after a day.
 */
final readonly class OAuthServer
{
    public const string READ = 'shop:read';
    public const string WRITE = 'shop:write';

    private const int CODE_SECONDS = 600;
    private const int ACCESS_SECONDS = 3600;
    private const int REFRESH_SECONDS = 30 * 86400;
    /** How long a self-registered client may wait for the owner's yes before it is forgotten. */
    private const int UNCONNECTED_CLIENT_SECONDS = 86400;
    private const string ACCESS_PREFIX = 'oat_';
    private const string REFRESH_PREFIX = 'ort_';

    public function __construct(
        private OAuthEndpoints $endpoints,
        private OAuthClientRepository $clients,
        private OAuthTokenRepository $tokens,
        private AuthorizationCodes $codes,
        private ClientDocuments $documents,
        private ApiKeys $keys,
        private ApiKeyRepository $keyRecords,
        private ClockInterface $clock,
    ) {}

    /** Whether the site can offer OAuth: it has an issuer address. */
    public function available(): bool
    {
        return $this->endpoints->issuer() !== null;
    }

    /**
     * The authorization server's metadata (RFC 8414).
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $this->endpoints->authorization(),
            'token_endpoint' => $this->endpoints->token(),
            'registration_endpoint' => $this->endpoints->registration(),
            'scopes_supported' => [self::READ, self::WRITE],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * The MCP endpoint's protected resource metadata (RFC 9728).
     *
     * @return array<string, mixed>
     */
    public function protectedResource(): array
    {
        return [
            'resource' => $this->endpoints->resource(),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => [self::READ, self::WRITE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Shop',
        ];
    }

    /**
     * Dynamic client registration (RFC 7591), for clients without a metadata document.
     *
     * @param array<array-key, mixed> $request
     *
     * @return array<string, mixed>
     *
     * @throws OAuthError
     */
    public function register(array $request): array
    {
        $uris = $request['redirect_uris'] ?? null;
        if (!\is_array($uris) || $uris === [] || \count($uris) > OAuthClient::MOST_REDIRECTS) {
            throw new OAuthError('invalid_redirect_uri', 'Give 1 to ' . OAuthClient::MOST_REDIRECTS . ' redirect_uris.');
        }
        $redirects = [];
        foreach ($uris as $uri) {
            if (!\is_string($uri) || !self::isRedirect($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'A redirect URI is https, a loopback http address, or an app\'s own scheme, without a fragment.');
            }
            $redirects[] = $uri;
        }
        $method = $request['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            throw new OAuthError('invalid_client_metadata', 'The shop takes public clients only: token_endpoint_auth_method "none", with PKCE.');
        }
        $name = \is_string($request['client_name'] ?? null) && trim($request['client_name']) !== '' ? $request['client_name'] : (string) (parse_url($redirects[0], \PHP_URL_HOST) ?? 'An app');
        $client = new OAuthClient('ocl_' . bin2hex(random_bytes(16)), $name, $redirects, $this->clock->now());
        $this->clients->save($client);

        return [
            'client_id' => $client->id(),
            'client_name' => $client->name(),
            'redirect_uris' => $client->redirectUris(),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'client_id_issued_at' => $client->createdAt()->getTimestamp(),
        ];
    }

    /**
     * Checks an authorization request before the owner is asked. A client or redirect address the shop cannot
     * trust is not redirected to; anything else is, with the error.
     *
     * @param array<array-key, mixed> $query
     *
     * @throws OAuthError
     */
    public function authorization(array $query): AuthorizationRequest
    {
        $clientId = \is_string($query['client_id'] ?? null) ? $query['client_id'] : '';
        $client = $this->client($clientId) ?? throw new OAuthError('invalid_client', 'The shop does not know this app.');
        $redirect = \is_string($query['redirect_uri'] ?? null) ? $query['redirect_uri'] : null;
        if ($redirect === null) {
            if (\count($client->redirectUris) !== 1) {
                throw new OAuthError('invalid_request', 'Name the redirect_uri.');
            }
            $redirect = $client->redirectUris[0];
        }
        if (!self::registered($redirect, $client->redirectUris)) {
            throw new OAuthError('invalid_request', 'This app did not register that redirect address.');
        }
        $state = \is_string($query['state'] ?? null) ? $query['state'] : null;
        if (($query['response_type'] ?? null) !== 'code') {
            throw new OAuthError('unsupported_response_type', 'The shop answers response_type "code" only.', 400, true, $redirect, $state);
        }
        $challenge = $query['code_challenge'] ?? null;
        if (!\is_string($challenge) || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) !== 1 || ($query['code_challenge_method'] ?? null) !== 'S256') {
            throw new OAuthError('invalid_request', 'PKCE is required: a code_challenge with method S256.', 400, true, $redirect, $state);
        }
        $resource = $query['resource'] ?? null;
        if ($resource !== null && (!\is_string($resource) || !$this->isResource($resource))) {
            throw new OAuthError('invalid_target', 'The shop gives tokens for its MCP endpoint only.', 400, true, $redirect, $state);
        }
        $scopes = \is_string($query['scope'] ?? null) ? explode(' ', $query['scope']) : [self::WRITE];

        return new AuthorizationRequest($client, $redirect, $challenge, $state, \in_array(self::WRITE, $scopes, true));
    }

    /**
     * The owner said yes: the client's key (made, or given this scope again), and the address to send them back
     * to with a code.
     */
    public function approve(AuthorizationRequest $request, int $userId, bool $write): string
    {
        $keyId = $this->keys->connect($request->client->id, $request->client->name, $userId, $write ? ApiKeyScope::ReadWrite : ApiKeyScope::Read);
        $code = $this->codes->issue([
            'client' => $request->client->id,
            'redirect' => $request->redirectUri,
            'challenge' => $request->codeChallenge,
            'key' => $keyId,
        ], self::CODE_SECONDS);

        return $this->back($request, ['code' => $code]);
    }

    /** The owner said no. */
    public function deny(AuthorizationRequest $request): string
    {
        return $this->back($request, ['error' => 'access_denied', 'error_description' => 'The shop\'s owner said no.']);
    }

    /** Where to send a redirectable error: back to the address the client registered; null for one that is not. */
    public function refusal(OAuthError $error): ?string
    {
        if (!$error->redirectable || $error->redirectUri === null) {
            return null;
        }

        return self::with($error->redirectUri, $error->toArray() + array_filter(['state' => $error->state, 'iss' => $this->endpoints->issuer()], static fn(?string $value): bool => $value !== null));
    }

    /**
     * The token endpoint: a code for tokens, or a refresh token for new ones.
     *
     * @param array<array-key, mixed> $form
     *
     * @return array<string, mixed>
     *
     * @throws OAuthError
     */
    public function token(array $form): array
    {
        $resource = $form['resource'] ?? null;
        if ($resource !== null && (!\is_string($resource) || !$this->isResource($resource))) {
            throw new OAuthError('invalid_target', 'The shop gives tokens for its MCP endpoint only.');
        }
        $clientId = \is_string($form['client_id'] ?? null) ? $form['client_id'] : '';

        return match ($form['grant_type'] ?? null) {
            'authorization_code' => $this->exchange($form, $clientId),
            'refresh_token' => $this->refresh($form, $clientId),
            default => throw new OAuthError('unsupported_grant_type', 'The shop takes authorization_code and refresh_token.'),
        };
    }

    /**
     * The token endpoint's answer: the tokens, or the error as RFC 6749 words it, with its status.
     *
     * @param array<array-key, mixed> $form
     *
     * @return array{int, array<string, mixed>}
     */
    public function tokenAnswer(array $form): array
    {
        try {
            return [200, $this->token($form)];
        } catch (OAuthError $e) {
            return [$e->status, $e->toArray()];
        }
    }

    /**
     * The registration endpoint's answer: the client, or the error as RFC 7591 words it, with its status.
     *
     * @param array<array-key, mixed> $request
     *
     * @return array{int, array<string, mixed>}
     */
    public function registrationAnswer(array $request): array
    {
        try {
            return [201, $this->register($request)];
        } catch (OAuthError $e) {
            return [$e->status, $e->toArray()];
        }
    }

    /** Who an access token acts as; null for one the shop has not, or one that ran out. */
    public function authenticate(string $accessToken): ?AuthenticatedKey
    {
        if (preg_match('/^oat_[a-f0-9]{48}$/', $accessToken) !== 1) {
            return null;
        }
        $token = $this->tokens->withAccessHash(hash('sha256', $accessToken));
        if ($token === null || $token->accessExpiresAt() <= $this->clock->now()) {
            return null;
        }
        $key = $token->key();

        return new AuthenticatedKey($key->id(), $key->userId(), $key->scope(), $key->name(), $key->assistant(), true);
    }

    /**
     * Forgets the clients that registered themselves more than a day ago and never got the owner's yes:
     * anyone may register one, so they must not pile up.
     *
     * @return int how many went
     */
    public function forgetUnconnectedClients(): int
    {
        return $this->clients->removeUnconnected($this->clock->now()->modify('-' . self::UNCONNECTED_CLIENT_SECONDS . ' seconds'));
    }

    /** A client the shop knows: one that registered, or one its metadata document describes. */
    public function client(string $clientId): ?OAuthClientView
    {
        if (str_starts_with($clientId, 'ocl_')) {
            $client = $this->clients->find($clientId);

            return $client === null ? null : new OAuthClientView($client->id(), $client->name(), $client->redirectUris());
        }
        if (!self::isDocumentAddress($clientId)) {
            return null;
        }
        $document = $this->documents->fetch($clientId);
        $uris = $document['redirect_uris'] ?? null;
        if ($document === null || ($document['client_id'] ?? null) !== $clientId || !\is_array($uris) || $uris === []) {
            return null;
        }
        $redirects = array_values(array_filter($uris, static fn(mixed $uri): bool => \is_string($uri) && self::isRedirect($uri)));
        if ($redirects === [] || ($document['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            return null;
        }
        $name = \is_string($document['client_name'] ?? null) && trim($document['client_name']) !== '' ? trim($document['client_name']) : (string) parse_url($clientId, \PHP_URL_HOST);
        $uri = \is_string($document['client_uri'] ?? null) && str_starts_with($document['client_uri'], 'https://') ? $document['client_uri'] : null;

        return new OAuthClientView($clientId, mb_substr($name, 0, OAuthClient::MAX_NAME_LENGTH), $redirects, $uri);
    }

    /**
     * @param array<array-key, mixed> $form
     *
     * @return array<string, mixed>
     */
    private function exchange(array $form, string $clientId): array
    {
        $code = \is_string($form['code'] ?? null) ? $form['code'] : '';
        $grant = $code === '' ? null : $this->codes->redeem($code);
        if ($grant === null || ($grant['client'] ?? null) !== $clientId) {
            throw new OAuthError('invalid_grant', 'The code is not one the shop gave this app, or it ran out or was used.');
        }
        $redirect = $form['redirect_uri'] ?? null;
        if ($redirect !== null && $redirect !== $grant['redirect']) {
            throw new OAuthError('invalid_grant', 'The redirect_uri is not the one the code was given for.');
        }
        $verifier = $form['code_verifier'] ?? null;
        if (!\is_string($verifier) || preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1
            || !\is_string($grant['challenge'] ?? null) || !hash_equals($grant['challenge'], self::base64url(hash('sha256', $verifier, true)))) {
            throw new OAuthError('invalid_grant', 'The code_verifier does not match the code_challenge.');
        }
        $key = \is_int($grant['key'] ?? null) ? $this->keyRecords->find($grant['key']) : null;
        if ($key === null) {
            throw new OAuthError('invalid_grant', 'The owner revoked this connection.');
        }
        [$access, $refresh, $now] = [self::secret(self::ACCESS_PREFIX), self::secret(self::REFRESH_PREFIX), $this->clock->now()];
        $this->tokens->removeExpired($now);
        $this->tokens->save(new OAuthToken($key, $clientId, hash('sha256', $access), $now->modify('+' . self::ACCESS_SECONDS . ' seconds'), hash('sha256', $refresh), $now->modify('+' . self::REFRESH_SECONDS . ' seconds')));

        return self::answer($access, $refresh, $key->scope());
    }

    /**
     * @param array<array-key, mixed> $form
     *
     * @return array<string, mixed>
     */
    private function refresh(array $form, string $clientId): array
    {
        $given = \is_string($form['refresh_token'] ?? null) ? $form['refresh_token'] : '';
        $hash = preg_match('/^ort_[a-f0-9]{48}$/', $given) === 1 ? hash('sha256', $given) : null;
        $token = $hash === null ? null : $this->tokens->withRefreshHash($hash);
        $now = $this->clock->now();
        if ($token === null && $hash !== null) {
            // A refresh token already rotated away comes again: it leaked, or the client lost the new one. Either
            // way the pair it belongs to ends; the app connects again through the owner.
            $replaced = $this->tokens->withPreviousRefreshHash($hash);
            if ($replaced !== null) {
                $this->tokens->remove($replaced);
                throw new OAuthError('invalid_grant', 'The refresh token was used already; the shop ended this connection\'s tokens. Connect again.');
            }
        }
        if ($token === null || $token->clientId() !== $clientId || $token->refreshExpiresAt() <= $now) {
            throw new OAuthError('invalid_grant', 'The refresh token is not one the shop gave this app, or it ran out or was used.');
        }
        [$access, $refresh] = [self::secret(self::ACCESS_PREFIX), self::secret(self::REFRESH_PREFIX)];
        $token->rotate(hash('sha256', $access), $now->modify('+' . self::ACCESS_SECONDS . ' seconds'), hash('sha256', $refresh), $now->modify('+' . self::REFRESH_SECONDS . ' seconds'));
        $this->tokens->save($token);

        return self::answer($access, $refresh, $token->key()->scope());
    }

    /** @return array<string, mixed> */
    private static function answer(string $access, string $refresh, ApiKeyScope $scope): array
    {
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_SECONDS,
            'refresh_token' => $refresh,
            'scope' => $scope === ApiKeyScope::ReadWrite ? self::READ . ' ' . self::WRITE : self::READ,
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function back(AuthorizationRequest $request, array $parameters): string
    {
        return self::with($request->redirectUri, $parameters + array_filter(['state' => $request->state, 'iss' => $this->issuer()], static fn(?string $value): bool => $value !== null));
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function with(string $uri, array $parameters): string
    {
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986);
    }

    private function issuer(): string
    {
        return $this->endpoints->issuer() ?? throw new OAuthError('temporarily_unavailable', 'The site offers no OAuth: turn on pretty permalinks.', 503);
    }

    /** The resource the client named is the MCP endpoint, written the same way. */
    private function isResource(string $resource): bool
    {
        $canonical = static fn(string $uri): string => rtrim(preg_replace_callback('#^([a-z][a-z0-9+.-]*://[^/]+)#i', static fn(array $m): string => strtolower($m[1]), $uri) ?? $uri, '/');

        return $canonical($resource) === $canonical($this->endpoints->resource());
    }

    /**
     * Whether the redirect address is one the client registered. A loopback one may come on any port (RFC 8252):
     * a native app picks a free port each time.
     *
     * @param list<string> $registered
     */
    private static function registered(string $redirect, array $registered): bool
    {
        if (\in_array($redirect, $registered, true)) {
            return true;
        }
        $given = parse_url($redirect);
        if (!\is_array($given) || !self::isLoopback($given)) {
            return false;
        }
        foreach ($registered as $uri) {
            $known = parse_url($uri);
            if (\is_array($known) && self::isLoopback($known) && ($known['scheme'] ?? '') === ($given['scheme'] ?? '') && ($known['host'] ?? '') === ($given['host'] ?? '')
                && ($known['path'] ?? '') === ($given['path'] ?? '') && ($known['query'] ?? '') === ($given['query'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * https anywhere; http only to this machine; or an app's own scheme (com.example.app:/callback). Never a
     * fragment, never javascript: or data:.
     */
    private static function isRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!\is_array($parts) || !isset($parts['scheme']) || isset($parts['fragment']) || \strlen($uri) > 2000) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);

        return match (true) {
            $scheme === 'https' => isset($parts['host']),
            $scheme === 'http' => self::isLoopback($parts),
            default => preg_match('/^[a-z][a-z0-9+-]*(\.[a-z0-9+-]+)+$/', $scheme) === 1,
        };
    }

    /** @param array<string, int|string> $parts */
    private static function isLoopback(array $parts): bool
    {
        return ($parts['scheme'] ?? '') === 'http' && \in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]', 'localhost'], true);
    }

    /** A client ID metadata document's address: https, with a path, nothing else. */
    private static function isDocumentAddress(string $clientId): bool
    {
        $parts = parse_url($clientId);

        return \is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host']) && ($parts['path'] ?? '/') !== '/'
            && !isset($parts['fragment']) && !isset($parts['user']) && !isset($parts['pass']) && \strlen($clientId) <= 255;
    }

    private static function secret(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(24));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
