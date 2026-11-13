<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Application;

/**
 * The shop as a UCP business (design 75): its profile, and each operation an agent asks for over REST or MCP. An
 * agent names its profile on every request; the shop reads it and answers with the capabilities both have. A
 * change carries an idempotency key: the same key and body is answered as before, the same key with another body
 * is a conflict.
 */
final readonly class UcpService
{
    public function __construct(
        private AgentCommerceSwitch $switch,
        private PlatformProfiles $profiles,
        private IdempotentAnswers $answers,
        private AgentCatalog $catalog,
        private AgentCheckouts $checkouts,
        private AgentEndpoints $endpoints,
    ) {}

    public function on(): bool
    {
        return $this->switch->on();
    }

    /**
     * /.well-known/ucp: the services, the capabilities, and (none yet) the payment handlers.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $capabilities = [];
        foreach (Ucp::CAPABILITIES as $name => $capability) {
            $capabilities[$name] = [['version' => Ucp::VERSION, 'spec' => Ucp::url($capability['spec']), 'schema' => Ucp::url($capability['schema'])] + (isset($capability['extends']) ? ['extends' => $capability['extends']] : [])];
        }

        return ['ucp' => [
            'version' => Ucp::VERSION,
            'services' => [Ucp::SERVICE => [
                ['version' => Ucp::VERSION, 'transport' => 'rest', 'endpoint' => $this->endpoints->rest(), 'schema' => Ucp::url('services/shopping/rest.openapi.json'), 'spec' => Ucp::url('specification/overview/')],
                ['version' => Ucp::VERSION, 'transport' => 'mcp', 'endpoint' => $this->endpoints->mcp(), 'schema' => Ucp::url('services/shopping/mcp.openrpc.json'), 'spec' => Ucp::url('specification/overview/')],
            ]],
            'capabilities' => $capabilities,
            'payment_handlers' => new \stdClass(),
        ]];
    }

    /**
     * @param array<mixed> $arguments the operation's input: a checkout, a search, an id
     * @param string       $agent     the agent's profile URL
     * @param string|null  $key       the idempotency key, for a change
     * @param string       $body      what the request carried, as sent: a key reused with another is a conflict
     *
     * @return array{status: int, body: array<string, mixed>}
     *
     * @throws UcpError
     */
    public function call(string $operation, array $arguments, string $agent, ?string $key, string $body): array
    {
        if (!$this->switch->on() || !isset(Ucp::OPERATIONS[$operation])) {
            throw new UcpError(404, 'not_found', 'The shop does not sell through agents.');
        }
        if (preg_match('#^https://[^\s]+$#', $agent) !== 1) {
            throw new UcpError(400, 'invalid_agent', 'Name your profile: UCP-Agent: profile="https://…".');
        }
        $capabilities = $this->negotiate($this->profiles->fetch($agent), $operation);
        if ($capabilities === []) {
            return ['status' => 200, 'body' => ['ucp' => ['version' => Ucp::VERSION, 'status' => 'error', 'capabilities' => new \stdClass()], 'messages' => [['type' => 'error', 'code' => 'capabilities_incompatible', 'content' => 'Your profile names none of the capabilities this operation needs, at version ' . Ucp::VERSION . '.', 'severity' => 'unrecoverable']]]];
        }
        // MCP asks for a key on completing and cancelling; REST on every change (UcpRestAction).
        if ($key === null && \in_array($operation, ['complete_checkout', 'cancel_checkout'], true)) {
            throw new UcpError(400, 'idempotency_key_required', 'Completing or cancelling carries an idempotency key.');
        }
        $changes = $key !== null && \in_array($operation, Ucp::CHANGES, true);
        if ($changes) {
            if ($key === '' || \strlen($key) > 255) {
                throw new UcpError(400, 'idempotency_key_required', 'An idempotency key is 1 to 255 characters.');
            }
            $known = $this->answers->get($operation . '|' . $key);
            if ($known !== null) {
                if (!hash_equals($known['body'], hash('sha256', $body))) {
                    throw new UcpError(409, 'idempotency_conflict', 'This Idempotency-Key was used with another request.');
                }

                return $known['answer'];
            }
        }
        $answer = $this->run($operation, $arguments, $agent);
        $answer['body'] = ['ucp' => ['version' => Ucp::VERSION, 'status' => ($answer['body']['error'] ?? false) === true ? 'error' : 'success', 'capabilities' => $capabilities]] + array_diff_key($answer['body'], ['error' => true]);
        if ($changes) {
            $this->answers->keep($operation . '|' . $key, hash('sha256', $body), $answer);
        }

        return $answer;
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function run(string $operation, array $arguments, string $agent): array
    {
        $id = \is_string($arguments['id'] ?? null) ? $arguments['id'] : '';
        $checkout = \is_array($arguments['checkout'] ?? null) ? $arguments['checkout'] : $arguments;

        return match ($operation) {
            'search_catalog' => ['status' => 200, 'body' => $this->search($arguments)],
            'lookup_catalog' => ['status' => 200, 'body' => ['products' => $this->catalog->lookup(array_values(array_filter(\is_array($arguments['ids'] ?? null) ? $arguments['ids'] : [], is_string(...))))]],
            'create_checkout' => self::created($this->checkouts->create($checkout, $agent)),
            'get_checkout' => ['status' => 200, 'body' => $this->checkouts->get($id)],
            'update_checkout' => ['status' => 200, 'body' => $this->checkouts->update($id, $checkout)],
            'complete_checkout' => ['status' => 200, 'body' => $this->checkouts->complete($id)],
            default => ['status' => 200, 'body' => $this->checkouts->cancel($id)],
        };
    }

    /**
     * @param array<string, mixed> $made
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function created(array $made): array
    {
        return ['status' => ($made['error'] ?? false) === true ? 200 : 201, 'body' => $made];
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function search(array $arguments): array
    {
        $filters = \is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [];
        $price = \is_array($filters['price'] ?? null) ? $filters['price'] : [];
        $pagination = \is_array($arguments['pagination'] ?? null) ? $arguments['pagination'] : [];
        $categories = array_values(array_filter(\is_array($filters['categories'] ?? null) ? $filters['categories'] : [], is_string(...)));
        $limit = \is_int($pagination['limit'] ?? null) ? $pagination['limit'] : 10;

        return $this->catalog->search(
            \is_string($arguments['query'] ?? null) ? $arguments['query'] : null,
            $categories,
            \is_int($price['min'] ?? null) ? $price['min'] : null,
            \is_int($price['max'] ?? null) ? $price['max'] : null,
            \is_string($pagination['cursor'] ?? null) ? $pagination['cursor'] : null,
            max(1, min(24, $limit)),
        );
    }

    /**
     * The capabilities both name, at a version both have, among those the operation answers with; an extension
     * whose parent is not among them is left out.
     *
     * @param array<string, mixed> $platform the agent's `ucp` member
     *
     * @return array<string, list<array{version: string}>>
     */
    private function negotiate(array $platform, string $operation): array
    {
        $theirs = \is_array($platform['capabilities'] ?? null) ? $platform['capabilities'] : [];
        $common = [];
        foreach (Ucp::OPERATIONS[$operation] as $name) {
            $versions = \is_array($theirs[$name] ?? null) ? array_column(array_filter($theirs[$name], is_array(...)), 'version') : [];
            if (\in_array(Ucp::VERSION, $versions, true)) {
                $common[$name] = [['version' => Ucp::VERSION]];
            }
        }
        foreach (Ucp::CAPABILITIES as $name => $capability) {
            if (isset($common[$name], $capability['extends']) && !isset($common[$capability['extends']])) {
                unset($common[$name]);
            }
        }

        return $common;
    }
}
